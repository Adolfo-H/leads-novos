# PACOTE 12 — Sincronização confiável HubSpot e webhooks

## Código confirmado na main

- `app/Services/HubSpotLeadSyncService.php` (`sync`, `syncManual`, `syncWithMode`, `syncWithoutLock`, `reconcilePartialSync`, `createDeal`, `createObject`, `associate`). **Ponto perigoso:** criação de negócio acontece antes da associação; se o POST remoto for aceito e a resposta se perder, o ID local pode permanecer vazio. A busca de recuperação por associação da empresa pode não encontrar o negócio ainda órfão.
- `app/Support/HubSpotHttpClient.php`, `app/Jobs/SyncCompanyToHubSpot.php`, `app/Jobs/SyncManualHubSpotOpportunity.php`.
- `app/Http/Controllers/HubSpotWebhookController.php` e `app/Http/Middleware/VerifyHubSpotWebhookSignature.php`; `app/Jobs/ProcessHubSpotWebhookEvent.php`; `app/Models/HubSpotWebhookEvent.php`.
- `docker/campaign-unsubscribe/nginx.conf`, `compose.yaml`, `hubspot-app/` (webhook configurado no manifesto).
- `app/Models/CompanyHubSpotLead.php`; flags de sincronização existentes em `config/services.php` e `.env.example`.

## Implementação obrigatória

1. Inventariar todos os fluxos de criação/atualização/associação, distinguir espelho CRM **de leitura** de escrita de leads. Nunca incluir escrituras remotas no dashboard ou na simples abertura de páginas.
2. Definir máquina de estados explícita de operação (exemplos: `not_started`, `in_progress`, `completed`, `remote_result_uncertain`, `manual_review`). Se faltarem colunas, criar migration ADITIVA e testes; migration real exige aprovação. Eventos externos/parciais devem ter identificador estável local; não registrar token HubSpot em logs.
3. Antes de POST, gravar intenção e chave/correlação local sob lock transacional. Em timeout/connection reset após POST, **não repetir a criação automaticamente**; marcar `remote_result_uncertain`. Recuperar apenas quando houver prova confiável de identidade remota (ID local, chave única remota validada ou confirmação manual). Busca por nome/data associada isoladamente é insuficiente. Não assumir suporte de campo personalizado sem verificar que existe no portal. Retorno 409/429/5xx recebe classificação própria.
4. Se existir recurso de idempotência oficial/documentado e configurado no portal, aproveitar; caso contrário, estado `manual_review` e rotina de conciliação read-only. Preferir segurança a uma falsa garantia de "exactly once".
5. Deixar tentativas HTTP GET/leituras recuperáveis com backoff limitado e `Retry-After`; **POST/PATCH/PUT sem retry cego**. Preservar política atual de criação de contatos e associação CNPJ confiável, sem unir empresas pelo nome/domínio.
6. Separar etapa "criado remotamente, falta associação" de "não foi criado"; após obter ID confiável, associação pode ser tentada separadamente, validando se já existe. Duplicidade comprovada >1 deve abortar para revisão manual, nunca escolher arbitrariamente.
7. Webhooks: conferir rota HTTPS, assinatura, timestamp e proteção contra replay; 401/403 para assinatura inválida; processamento idempotente por evento; não registrar corpo sensível; controle de concorrência; falhas recuperáveis via job e fila, sem duplicar alteração de negócio.
8. No Compose/ngrok, separar roteamento do webhook HubSpot de unsubscribe. Não expor endpoints privados do Laravel indiscriminadamente. Criar teste automatizado da configuração nginx/routing; verifique se `ngrok` aponta só para gateway de descadastro e bloqueia `/webhooks/hubspot`.
9. Adicionar comando diagnóstico `hubspot:sync-audit` somente leitura: contagens de leads com IDs remotos nulos, estados incertos, associações ausentes, chaves duplicadas locais, jobs falhos por categoria. Sem imprimir empresas/contatos por padrão. Não iniciar sincronizações no comando diagnóstico.

## Testes obrigatórios

- Novos testes em `tests/Feature/HubSpot/HubSpotSyncUncertainResultTest.php` e `tests/Feature/HubSpot/HubSpotWebhookSignatureAndRetryTest.php` com `Http::fake()`; se precisar, adaptar nomes.
- Casos: (a) POST aceito remotamente e resposta perdida, (b) retry do mesmo job não cria segundo negócio, (c) associação falha após criação, (d) evento webhook repetido processado uma vez, (e) assinatura inválida rejeitada, (f) dois workers concorrentes, (g) cliente/negócio ativo bloqueia prospecção, (h) portal sem propriedade de correlação segura exige revisão manual.
- Testes existentes: `tests/Feature/HubSpot`, `tests/Feature/Companies/HubSpotLead*`, suites comerciais relacionadas.
- Teste somente leitura existente do pacote: `./vendor/bin/sail artisan tinker --execute='require base_path("docs/prospector-pacotes-11-17/scripts/auditar_hubspot.php");'`.

## Aceite e guardrail

- Nenhum request HTTP remoto de escrita realizado por testes ou nesta implementação; os novos estados não podem quebrar registros antigos (migração/backfill cuidadoso).
- Não alterar `HUBSPOT_LEAD_SYNC_ENABLED` automaticamente e nem credenciais.
- Recuperação automática não pode criar duplicatas, mesmo em timeout pós-aceite; endpoint webhook validado com assinatura.
- Ao detectar necessidade de credencial/ação no HubSpot real, listar procedimento e aguardar autorização em vez de simular sucesso.

## Reversão

Código via Git. Migrations aditivas somente após revisão. Nunca remover registros do HubSpot para "voltar" teste; apenas simular via fake e documentar como recuperar estados internos.
