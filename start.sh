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


echo "1/7  Preparando serviços de background..."

docker compose \
    --profile background \
    stop \
    queue-worker \
    scheduler \
    >/dev/null 2>&1 \
    || true


echo "2/7  Subindo Docker/Sail..."

./vendor/bin/sail up -d


echo "3/7  Aguardando Laravel..."

READY=0

for ATTEMPT in $(seq 1 45)
do
    if ./vendor/bin/sail artisan about >/dev/null 2>&1
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


echo "4/7  Limpando caches..."

./vendor/bin/sail artisan optimize:clear >/dev/null


echo "5/7  Iniciando fila e scheduler..."

docker compose \
    --profile background \
    up -d \
    queue-worker \
    scheduler


echo "     Iniciando workers HubSpot prioritarios..."

docker compose \
    --profile background \
    up -d \
    --scale hubspot-webhook-worker=2 \
    --scale hubspot-realtime-worker=2 \
    hubspot-webhook-worker \
    hubspot-realtime-worker \
    hubspot-bulk-worker


echo "     Testando workers prioritarios..."

./vendor/bin/sail artisan \
    hubspot:health-ping \
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
        up -d \
        ngrok
else
    echo "     ngrok não configurado; ignorando túnel."
fi


echo "6/7  Iniciando frontend..."

if docker compose exec \
    -T \
    laravel.test \
    sh -lc \
    "ps aux 2>/dev/null | grep -E '[v]p dev|[v]ite-plus' >/dev/null"
then
    echo "     Frontend já está rodando."
else
    mkdir -p storage/logs

    docker compose exec \
        -d \
        --user sail \
        laravel.test \
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

        mkdir -p storage/logs

        docker compose exec \
            -d \
            --user sail \
            laravel.test \
            sh -lc \
            "printf '\n===== STARTUP HUBSPOT %s =====\n' \"\$(date '+%Y-%m-%d %H:%M:%S')\" >> storage/logs/hubspot-startup-sync.log; php artisan hubspot:startup-refresh --limit=${STARTUP_LIMIT} >> storage/logs/hubspot-startup-sync.log 2>&1"

        echo ""
        echo "     Conferência HubSpot iniciada."
        echo "     Empresas nesta conferência (0 = todas): ${STARTUP_LIMIT}"

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
echo "  conferência iniciada imediatamente"
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
