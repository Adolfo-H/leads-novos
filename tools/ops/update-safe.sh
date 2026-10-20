#!/usr/bin/env bash

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

MODE="dry-run"
BACKUP_FILE=""
RUN_MIGRATIONS=0
START_WORKERS=0
START_HUBSPOT_WORKERS=0
ALLOW_PRODUCTION=0

usage()
{
    cat <<'EOF'
Usage: tools/ops/update-safe.sh [options]

Dry-run is the default and performs read-only checks only.

Options:
  --dry-run                  Inspect the proposed update without changing it.
  --apply                    Install dependencies and restart the app.
  --backup PATH              Require a non-empty Postgres backup outside this repository.
  --migrate                  With --apply, run migrate --pretend then migrate --force.
  --start-workers            With --apply, start default workers and scheduler.
  --start-hubspot-workers    With --apply, explicitly start HubSpot workers.
  --allow-production         Required to apply when APP_ENV=production.
  -h, --help                 Show this help.

Workers must already be gracefully drained and stopped before --apply.
The campaign worker is never started by this script.
EOF
}

while (($# > 0)); do
    case "$1" in
        --dry-run)
            MODE="dry-run"
            ;;
        --apply)
            MODE="apply"
            ;;
        --backup)
            if (($# < 2)); then
                echo "ERRO: --backup requer um caminho." >&2
                exit 2
            fi
            BACKUP_FILE="$2"
            shift
            ;;
        --migrate)
            RUN_MIGRATIONS=1
            ;;
        --start-workers)
            START_WORKERS=1
            ;;
        --start-hubspot-workers)
            START_HUBSPOT_WORKERS=1
            ;;
        --allow-production)
            ALLOW_PRODUCTION=1
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "ERRO: opção desconhecida: $1" >&2
            usage >&2
            exit 2
            ;;
    esac
    shift
done

if [[ "$MODE" != "apply" ]] && {
    ((RUN_MIGRATIONS == 1)) \
        || ((START_WORKERS == 1)) \
        || ((START_HUBSPOT_WORKERS == 1)) \
        || ((ALLOW_PRODUCTION == 1))
}; then
    echo "ERRO: opções de execução exigem --apply." >&2
    exit 2
fi

if ((START_HUBSPOT_WORKERS == 1 && START_WORKERS == 0)); then
    echo "ERRO: --start-hubspot-workers também exige --start-workers." >&2
    exit 2
fi

require_command()
{
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "ERRO: comando obrigatório não encontrado: $1" >&2
        exit 1
    fi
}

require_command docker
require_command git
require_command df
require_command realpath

echo "=== Update seguro: ${MODE} ==="

docker compose config --quiet
git diff --check

echo
echo "Dependências alteradas:"
git status --short -- composer.json composer.lock package.json package-lock.json npm-shrinkwrap.json
git diff -- composer.json composer.lock package.json package-lock.json npm-shrinkwrap.json
git diff --cached -- composer.json composer.lock package.json package-lock.json npm-shrinkwrap.json

echo
echo "Migrations alteradas (não executadas nesta etapa):"
git status --short -- database/migrations
git diff -- database/migrations
git diff --cached -- database/migrations

echo
echo "Serviços em execução:"
running_services="$(docker compose --profile background ps --services --status running)"
if [[ -n "$running_services" ]]; then
    printf '%s\n' "$running_services"
else
    echo "  nenhum"
fi

echo
echo "Espaço livre no host:"
df -h "$ROOT"

if printf '%s\n' "$running_services" | grep -Fxq "receita-data"; then
    echo
    echo "Espaço livre no volume Receita:"
    docker compose exec -T receita-data df -h /real-data
fi

if [[ -n "$BACKUP_FILE" ]]; then
    if [[ "$BACKUP_FILE" != /* ]]; then
        BACKUP_FILE="$ROOT/$BACKUP_FILE"
    fi

    if [[ ! -f "$BACKUP_FILE" || ! -s "$BACKUP_FILE" || ! -r "$BACKUP_FILE" ]]; then
        echo "ERRO: backup ausente, vazio ou ilegível: $BACKUP_FILE" >&2
        exit 1
    fi
    BACKUP_FILE="$(realpath -e "$BACKUP_FILE")"
    if [[ "$BACKUP_FILE" == "$ROOT/"* ]]; then
        echo "ERRO: mantenha o backup fora deste repositório." >&2
        exit 1
    fi
    echo
    echo "Backup informado: arquivo legível e não vazio (integridade do dump não verificada)."
else
    echo
    echo "Backup: não informado; qualquer --apply ficará bloqueado."
fi

if [[ "$MODE" == "dry-run" ]]; then
    echo
    echo "DRY-RUN concluído: nenhum pacote, migration, worker ou container foi alterado."
    exit 0
fi

if [[ -z "$BACKUP_FILE" ]]; then
    echo "ERRO: --apply requer --backup PATH." >&2
    exit 1
fi

if [[ -f package.json && ! -f package-lock.json && ! -f npm-shrinkwrap.json ]]; then
    echo "ERRO: package.json existe sem lockfile; não é seguro atualizar npm." >&2
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    echo "ERRO: --apply exige worktree limpo e código versionado." >&2
    exit 1
fi

app_env="production"
if [[ -f .env ]]; then
    if grep -qE '^APP_ENV=' .env; then
        app_env="$(
            grep -E '^APP_ENV=' .env \
                | tail -n 1 \
                | cut -d= -f2- \
                | tr -d "\"'"
        )"
    fi
fi
if [[ -z "$app_env" ]]; then
    app_env="production"
fi

if [[ "$app_env" == "production" && "$ALLOW_PRODUCTION" != "1" ]]; then
    echo "ERRO: produção exige autorização explícita --allow-production." >&2
    exit 1
fi

workers=(
    queue-worker
    scheduler
    hubspot-webhook-worker
    hubspot-realtime-worker
    hubspot-bulk-worker
    campaigns-worker
)

for service in "${workers[@]}"; do
    if printf '%s\n' "$running_services" | grep -Fxq "$service"; then
        echo "ERRO: ${service} ainda está ativo. Faça drain gracioso e confirme jobs incertos antes do update." >&2
        exit 1
    fi
done

for service in pgsql redis laravel.test; do
    id="$(docker compose ps -q "$service" | head -n 1)"

    if [[ -z "$id" ]]; then
        echo "ERRO: serviço necessário não está em execução: $service" >&2
        exit 1
    fi

    health="$(
        docker inspect \
            --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' \
            "$id"
    )"

    if [[ "$health" != "healthy" ]]; then
        echo "ERRO: serviço necessário não está saudável: $service ($health)." >&2
        exit 1
    fi
done

maintenance_enabled=0
restore_app()
{
    if ((maintenance_enabled == 1)); then
        if docker compose exec -T laravel.test php artisan up; then
            maintenance_enabled=0
        else
            echo "ERRO: não foi possível retirar a aplicação do modo de manutenção." >&2
        fi
    fi
}
trap restore_app EXIT

wait_for_app_health()
{
    local id=""
    local health=""

    for attempt in $(seq 1 60); do
        id="$(docker compose ps -q laravel.test | head -n 1)"

        if [[ -n "$id" ]]; then
            health="$(
                docker inspect \
                    --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' \
                    "$id"
            )"

            if [[ "$health" == "healthy" ]]; then
                return 0
            fi
        fi

        sleep 1
    done

    echo "ERRO: Laravel não ficou saudável após a atualização (estado: ${health:-ausente})." >&2
    return 1
}

docker compose exec -T laravel.test php artisan down --retry=60
maintenance_enabled=1

docker compose exec -T laravel.test composer install \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --optimize-autoloader

if [[ -f package-lock.json || -f npm-shrinkwrap.json ]]; then
    docker compose exec -T laravel.test npm ci
    docker compose exec -T laravel.test npm run build
fi

if ((RUN_MIGRATIONS == 1)); then
    docker compose exec -T laravel.test php artisan migrate --pretend --force
    docker compose exec -T laravel.test php artisan migrate --force
fi

docker compose exec -T laravel.test php artisan up
maintenance_enabled=0
docker compose restart laravel.test
wait_for_app_health

if ((START_WORKERS == 1)); then
    docker compose --profile background up -d --no-recreate queue-worker scheduler

    if ((START_HUBSPOT_WORKERS == 1)); then
        docker compose --profile background up -d --no-recreate \
            --scale hubspot-webhook-worker=2 \
            --scale hubspot-realtime-worker=2 \
            hubspot-webhook-worker \
            hubspot-realtime-worker \
            hubspot-bulk-worker
    fi
fi

echo "Update concluído. A campanha não foi iniciada; valide a saúde dos serviços e jobs antes de liberá-la."
