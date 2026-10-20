#!/usr/bin/env bash

set -Eeuo pipefail
set +H

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

cd "$ROOT"


echo ""
echo "================================================"
echo " EXPORTCONTROL PROSPECTOR"
echo " INICIALIZACAO LOCAL"
echo "================================================"
echo ""


if [ ! -x ./vendor/bin/sail ]; then
    echo "ERRO: Laravel Sail não encontrado."
    echo "Execute composer install primeiro."
    exit 1
fi


env_value()
{
    local key="$1"
    local default="$2"
    local line=""

    if [ -f .env ]; then
        line="$(
            grep -E "^${key}=" .env \
                | tail -n 1 \
                || true
        )"
    fi

    if [ -z "$line" ]; then
        printf '%s' "$default"
        return
    fi

    local value="${line#*=}"

    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"

    printf '%s' "$value"
}

rotate_log_file()
{
    local file="$1"
    local max_bytes=10485760
    local size=0

    if [ ! -f "$file" ]; then
        return
    fi

    size="$(wc -c < "$file")"

    if [ "$size" -lt "$max_bytes" ]; then
        return
    fi

    rm -f "${file}.5"

    for index in 4 3 2 1; do
        if [ -f "${file}.${index}" ]; then
            mv "${file}.${index}" "${file}.$((index + 1))"
        fi
    done

    mv "$file" "${file}.1"
}

report_service()
{
    local service="$1"
    local ids=""
    local id=""
    local status=""

    ids="$(docker compose --profile background ps -q "$service")"

    if [ -z "$ids" ]; then
        printf '  %-34s %s\n' "$service" "não iniciado"
        return
    fi

    while IFS= read -r id; do
        [ -z "$id" ] && continue

        status="$(
            docker inspect \
                --format '{{.State.Status}}{{if .State.Health}} (health: {{.State.Health.Status}}){{end}}' \
                "$id"
        )"

        printf '  %-34s %s\n' "$service" "$status"
    done <<< "$ids"
}

start_service_if_absent()
{
    local service="$1"
    local replicas="${2:-1}"
    local ids=""

    ids="$(docker compose --profile background ps -q "$service")"

    if [ -n "$ids" ]; then
        printf '     %s já está em execução; sem alterar suas réplicas.\n' "$service"
        return
    fi

    if [ "$replicas" -gt 1 ]; then
        docker compose --profile background up -d --no-recreate \
            --scale "${service}=${replicas}" \
            "$service"
        return
    fi

    docker compose --profile background up -d --no-recreate "$service"
}

wait_for_service_health()
{
    local service="$1"
    local ids=""
    local id=""
    local health=""

    for attempt in $(seq 1 60); do
        ids="$(docker compose ps -q "$service")"
        id="$(printf '%s\n' "$ids" | head -n 1)"

        if [ -n "$id" ]; then
            health="$(
                docker inspect \
                    --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' \
                    "$id"
            )"

            if [ "$health" = "healthy" ]; then
                return 0
            fi
        fi

        sleep 1
    done

    echo "ERRO: serviço ${service} não ficou saudável (estado: ${health:-ausente})."
    return 1
}


# STARTUP_SAFE_V20_1: nunca interromper workers nem recriar serviços
# com dados/filas ativos durante uma inicialização de rotina.
echo "1/7  Preservando workers e scheduler ativos..."
echo "2/7  Subindo dependências e Laravel sem recriar contêineres..."

docker compose up -d --no-recreate laravel.test

if ! docker compose up -d --no-recreate receita-data; then
    echo "AVISO: não foi possível iniciar receita-data; a aplicação Laravel continuará disponível."
fi

APP_CONTAINER="$(docker compose ps -q laravel.test | head -n 1)"

if [ -z "$APP_CONTAINER" ]; then
    echo "ERRO: contêiner Laravel não encontrado."
    exit 1
fi


echo "3/7  Aguardando Laravel..."

wait_for_service_health pgsql
wait_for_service_health redis
wait_for_service_health laravel.test

docker exec --user sail --workdir /var/www/html \
    "$APP_CONTAINER" php artisan about >/dev/null


echo "4/7  Limpando somente artefatos compilados..."

# NÃO executar optimize:clear/cache:clear: podem remover locks,
# rate limits e outras chaves compartilhadas enquanto jobs rodam.
for COMMAND in config:clear route:clear view:clear event:clear; do
    docker exec --user sail --workdir /var/www/html \
        "$APP_CONTAINER" php artisan "$COMMAND" >/dev/null
done


echo "5/7  Iniciando fila e scheduler..."

start_service_if_absent queue-worker
start_service_if_absent scheduler


echo "     Iniciando workers HubSpot prioritarios..."

start_service_if_absent hubspot-webhook-worker 2
start_service_if_absent hubspot-realtime-worker 2
start_service_if_absent hubspot-bulk-worker


echo "     Heartbeats de workers são enviados pelo scheduler."


NGROK_TOKEN="$(
    env_value \
        NGROK_AUTHTOKEN \
        ""
)"

NGROK_DOMAIN="$(
    env_value \
        NGROK_DOMAIN \
        ""
)"


if \
    [ -n "$NGROK_TOKEN" ] \
    && [ -n "$NGROK_DOMAIN" ]
then
    echo "     Iniciando túnel público de descadastro..."

    docker compose \
        --profile background \
        up -d --no-recreate \
        ngrok
else
    echo "     ngrok não configurado; ignorando túnel."
fi


echo "6/7  Iniciando frontend..."

if docker exec \
    "$APP_CONTAINER" \
    sh -lc \
    "ps aux 2>/dev/null | grep -E '[v]p dev|[v]ite-plus' >/dev/null"
then
    echo "     Frontend já está rodando."
else
    mkdir -p storage/logs
    rotate_log_file storage/logs/vite-dev.log

    docker exec \
        -d --user sail --workdir /var/www/html \
        "$APP_CONTAINER" \
        sh -lc \
        "npm run dev >> storage/logs/vite-dev.log 2>&1"

    echo "     Frontend iniciado."
fi


echo "7/7  Verificando opção de conferência inicial do HubSpot..."

STARTUP_ENABLED="$(
    env_value \
        HUBSPOT_STARTUP_SYNC_ENABLED \
        "false"
)"

STARTUP_LIMIT="$(
    env_value \
        HUBSPOT_STARTUP_SYNC_LIMIT \
        "100"
)"

STARTUP_LIMIT_VALID=1

if ! [[ "$STARTUP_LIMIT" =~ ^[0-9]+$ ]]
then
    STARTUP_LIMIT_VALID=0
elif [ "$STARTUP_LIMIT" -lt 1 ] || [ "$STARTUP_LIMIT" -gt 100 ]; then
    STARTUP_LIMIT_VALID=0
fi


if \
    [ "$STARTUP_ENABLED" = "true" ] \
    || [ "$STARTUP_ENABLED" = "1" ]
then

    if [ "$STARTUP_LIMIT_VALID" != "1" ]; then
        echo "     Conferência inicial ignorada: limite deve estar entre 1 e 100."
    elif grep -qE \
        '^HUBSPOT_ACCESS_TOKEN=.+$' \
        .env 2>/dev/null
    then

        # Evita enfileirar novamente toda a base em inicializações
        # de rotina enquanto o worker Bulk continua processando.
        BULK_PENDING="$(
            docker exec --user sail --workdir /var/www/html \
                "$APP_CONTAINER" php -r '
require getcwd()."/vendor/autoload.php";
$app = require getcwd()."/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo (int) \Illuminate\Support\Facades\Queue::connection("redis")->size("hubspot-bulk");
' 2>/dev/null
        )" || BULK_PENDING=""

        if ! [[ "$BULK_PENDING" =~ ^[0-9]+$ ]]; then
            echo "     Não foi possível verificar a fila Bulk; atualização inicial ignorada por segurança."
        elif [ "$BULK_PENDING" -gt 0 ]; then
            echo "     Fila Bulk tem ${BULK_PENDING} jobs; atualização inicial ignorada para evitar duplicações."
        elif docker exec "$APP_CONTAINER" sh -lc \
            "ps aux 2>/dev/null | grep -E '[p]hp artisan hubspot:startup-refresh' >/dev/null"; then
            echo "     Atualização inicial do HubSpot já está em execução."
        else
            mkdir -p storage/logs
            rotate_log_file storage/logs/hubspot-startup-sync.log

            docker exec -d --user sail --workdir /var/www/html \
                "$APP_CONTAINER" sh -lc \
                "printf '\n===== STARTUP HUBSPOT %s =====\n' \"\$(date '+%Y-%m-%d %H:%M:%S')\" >> storage/logs/hubspot-startup-sync.log; php artisan hubspot:startup-refresh --limit=${STARTUP_LIMIT} >> storage/logs/hubspot-startup-sync.log 2>&1"

            echo ""
            echo "     Conferência HubSpot iniciada."
            echo "     Empresas nesta conferência (0 = todas): ${STARTUP_LIMIT}"
        fi

    else

        echo ""
        echo "     HubSpot não iniciado:"
        echo "     HUBSPOT_ACCESS_TOKEN não configurado."

    fi

else
    echo "     Conferência inicial desativada."
fi

echo ""
echo "================================================"
echo " PROJETO INICIADO"
echo "================================================"
echo "Saúde dos serviços:"
report_service laravel.test
report_service pgsql
report_service redis
report_service receita-data
report_service queue-worker
report_service scheduler
report_service hubspot-webhook-worker
report_service hubspot-realtime-worker
report_service hubspot-bulk-worker
report_service campaigns-worker
report_service campaigns-unsubscribe-gateway
report_service ngrok
echo "Espaço livre no host:"
df -h "$ROOT"

if [ -n "$(docker compose ps -q receita-data)" ]; then
    echo "Espaço livre no volume Receita:"
    docker compose exec -T receita-data df -h /real-data
fi

echo ""
echo "Aplicação:"
echo "  http://localhost"
echo ""
echo "Fila:"
echo "  automática"
echo ""
echo "Scheduler:"
echo "  automático"
echo ""
echo "HubSpot:"
echo "  atualização inicial desligada por padrão"
echo "  health-ping agendado: hubspot:health-ping"
echo ""
echo "Se a conferência inicial estiver explicitamente habilitada:"
echo ""
echo "  tail -f storage/logs/hubspot-startup-sync.log"
echo ""
echo "Ver containers:"
echo ""
echo "  docker compose --profile background ps"
echo ""
