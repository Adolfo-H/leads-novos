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


# STARTUP_SAFE_V20_1: nunca interromper workers nem recriar serviços
# com dados/filas ativos durante uma inicialização de rotina.
echo "1/7  Preservando workers e scheduler ativos..."
echo "2/7  Subindo Laravel sem recriar contêineres..."

docker compose up -d --no-recreate laravel.test

APP_CONTAINER="$(docker compose ps -q laravel.test | head -n 1)"

if [ -z "$APP_CONTAINER" ]; then
    echo "ERRO: contêiner Laravel não encontrado."
    exit 1
fi


echo "3/7  Aguardando Laravel..."

READY=0

for ATTEMPT in $(seq 1 45)
do
    if docker exec --user sail --workdir /var/www/html \
        "$APP_CONTAINER" php artisan about >/dev/null 2>&1
    then
        READY=1
        break
    fi

    sleep 1
done


if [ "$READY" != "1" ]; then
    echo ""
    echo "ERRO: Laravel não ficou disponível."
    exit 1
fi


echo "4/7  Limpando somente artefatos compilados..."

# NÃO executar optimize:clear/cache:clear: podem remover locks,
# rate limits e outras chaves compartilhadas enquanto jobs rodam.
for COMMAND in config:clear route:clear view:clear event:clear; do
    docker exec --user sail --workdir /var/www/html \
        "$APP_CONTAINER" php artisan "$COMMAND" >/dev/null
done


echo "5/7  Iniciando fila e scheduler..."

docker compose \
    --profile background \
    up -d --no-recreate \
    queue-worker \
    scheduler


echo "     Iniciando workers HubSpot prioritarios..."

docker compose \
    --profile background \
    up -d --no-recreate \
    --scale hubspot-webhook-worker=2 \
    --scale hubspot-realtime-worker=2 \
    hubspot-webhook-worker \
    hubspot-realtime-worker \
    hubspot-bulk-worker


echo "     Testando workers prioritarios..."

docker exec --user sail --workdir /var/www/html \
    "$APP_CONTAINER" php artisan hubspot:health-ping \
    >/dev/null 2>&1 \
    || true


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
    echo "     Iniciando túnel de webhooks..."

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

    docker exec \
        -d --user sail --workdir /var/www/html \
        "$APP_CONTAINER" \
        sh -lc \
        "npm run dev >> storage/logs/vite-dev.log 2>&1"

    echo "     Frontend iniciado."
fi


echo "7/7  Iniciando conferência do HubSpot..."

STARTUP_ENABLED="$(
    env_value \
        HUBSPOT_STARTUP_SYNC_ENABLED \
        "true"
)"

STARTUP_LIMIT="$(
    env_value \
        HUBSPOT_STARTUP_SYNC_LIMIT \
        "0"
)"


if ! [[ "$STARTUP_LIMIT" =~ ^[0-9]+$ ]]
then
    STARTUP_LIMIT=0
fi


if [ "$STARTUP_LIMIT" -lt 1 ]; then
    STARTUP_LIMIT=0
fi


if \
    [ "$STARTUP_ENABLED" = "true" ] \
    || [ "$STARTUP_ENABLED" = "1" ]
then

    if grep -qE \
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
echo "  atualização inicial condicionada à fila Bulk"
echo "  depois continua a cada 5 minutos"
echo ""
echo "Acompanhar conferência inicial:"
echo ""
echo "  tail -f storage/logs/hubspot-startup-sync.log"
echo ""
echo "Ver containers:"
echo ""
echo "  docker compose --profile background ps"
echo ""
