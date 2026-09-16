# Prospector ExportControl

Aplicação interna da ExportControl para descoberta, enriquecimento, qualificação e acompanhamento comercial de leads empresariais.

## Stack

Laravel 13, PHP 8.5, Livewire 4, Flux UI, PostgreSQL, Redis, Laravel Sail, Python 3.12, DuckDB, Parquet, HubSpot CRM, Tavily, Pest, PHPStan e Pint.

## Ambiente local

Instalar dependências: `composer install`

Criar ambiente: `cp .env.example .env`

Gerar chave: `php artisan key:generate`

Subir containers: `./vendor/bin/sail up -d`

Executar migrations: `./vendor/bin/sail artisan migrate`

## Receita Federal

O serviço Python fica em `tools/receita`.

Health check local: `curl http://localhost:8091/health`

Testes Python: `./vendor/bin/sail exec receita-data python -m unittest discover -s /app -p 'test_*.py'`

Dados processados, Parquet, ambientes virtuais e bytecode Python não devem ser versionados.

## Filas

Worker manual: `./vendor/bin/sail artisan queue:work redis`

O retry_after deve permanecer maior que o maior timeout dos jobs.

Existe também o profile Docker `background`, com worker e scheduler permanentes.

Para iniciá-lo conscientemente: `COMPOSE_PROFILES=background ./vendor/bin/sail up -d`

## Scheduler

Execução manual: `./vendor/bin/sail artisan schedule:work`

O scheduler recupera processamentos travados, sincronizações incompletas do HubSpot e atualiza status comerciais.

## HubSpot

A sincronização automática cria e relaciona Company, Contact quando disponível e Deal.

Ela possui job próprio, retry automático, proteção contra concorrência, recuperação de sincronização parcial e reconciliação de registros remotos.

Principais variáveis: HUBSPOT_ACCESS_TOKEN, HUBSPOT_BASE_URL, HUBSPOT_PORTAL_ID, HUBSPOT_LEAD_SYNC_ENABLED, HUBSPOT_LEAD_MIN_SCORE, HUBSPOT_LEAD_PIPELINE, HUBSPOT_LEAD_INITIAL_STAGE e HUBSPOT_LEAD_DISCARDED_STAGES.

Nunca versione tokens ou credenciais.

## Segurança

O cadastro público fica desabilitado por padrão.

APP_ALLOW_REGISTRATION=true deve ser utilizado apenas quando a abertura temporária de cadastro for realmente desejada.

Rotas internas utilizam autenticação e verificação de e-mail.

## Pesquisa de exportação

O provedor padrão é Tavily.

As configurações principais são TAVILY_API_KEY, TAVILY_BASE_URL, TAVILY_MAX_RESULTS, EXPORT_RESEARCH_ENABLED e EXPORT_RESEARCH_COOLDOWN_DAYS.

## Qualidade

Validação PHP completa: `./vendor/bin/sail composer ci:check`

Validação de diff: `git diff --check`

Conferência de alterações: `git status`
