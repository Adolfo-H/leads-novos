# Validação

## Baseline

- Primeira execução dos testes críticos com SQLite em memória, mailers `array`, envio de campanha desativado e fila/cache/sessão isolados: 21 passaram e 2 falharam no caminho de cache do Livewire Blaze em `/var/www/html/storage/framework/views/livewire/views/...`.
- Repetição com `VIEW_COMPILED_PATH=/tmp/prospector-audit-views-20261112`: 23 passaram, 112 assertions. O cache usado foi temporário; `storage` operacional não foi limpo.
- Não foram executados `composer setup`, `composer ci:check` ou `scripts/ci/quality-gate.sh`: efeitos incluem geração de chave, migrations ou `artisan config:clear` sem isolamento suficiente.

## Testes executados

Comandos Pest no WSL Ubuntu contra SQLite `:memory:`, `DB_URL` vazio, `MAIL_MAILER=array`, `CAMPAIGN_MAIL_MAILER=array`, `CAMPAIGN_MAIL_ENABLED=false`, `CAMPAIGN_MAIL_LIVE_ENABLED=false`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, `SESSION_DRIVER=array` e cache Blade temporário.

| Seletores | Resultado |
|---|---|
| `CampaignContactsSelectionV91Test`, `CampaignContactsTransferV92Test`, `ContactsSelectionCampaignV92Test`, `WhatsappVerificationV941Test`, `CampaignUiRuntimeV92Test` | 20 testes, 88 assertions, aprovados |
| `CampaignWizardV93Test`, `CampaignConsolidationV94Test`, `ContactsReceitaUnifiedV10Test`, `ContactsFigmaV66Test`, `ContactsFigmaV662Test` | 20 testes, 138 assertions, aprovados |
| `CampaignConsolidationV94Test`, `CampaignSafetyV3Test` | 15 testes, 92 assertions, aprovados |
| Testes adicionais de consolidação, envio e seleção em lote | 12 testes, 59 assertions, aprovados |
| `presence-classifier-v92.test.mjs` com Node 24.19.0 | 4 testes aprovados |
| `git diff --check` | Aprovado antes da reconstrução dos relatórios; repetir após as alterações atuais |

Os testes não enviaram mensagens; integrações e mailers foram explicitamente falsos/desativados. Nenhum worker, scheduler, Docker ou integração real foi iniciado.

## Continuação crítica — 2026-11-13

- Ambiente usado nos testes dirigidos: WSL Ubuntu; `APP_ENV=testing`, SQLite `:memory:`, `DB_URL` vazio, `MAIL_MAILER=array`, `CAMPAIGN_MAIL_MAILER=array`, `CAMPAIGN_MAIL_ENABLED=false`, `CAMPAIGN_MAIL_LIVE_ENABLED=false`, `CAMPAIGN_MAIL_PILOT_ENABLED=false`, fila `sync`, cache e sessão `array`, Blade temporário em `/tmp/prospector-audit-views-20261113`. Nenhum processo externo/worker foi iniciado e os testes não fizeram envio SMTP, escrita HubSpot ou probe WhatsApp real.
- `php artisan test` com 14 seletores dos fluxos campanhas/WhatsApp/runtime legado: **64 testes, 388 assertions aprovados**. Inclui `CampaignUiRuntimeV92Test`, seleção/transferência V91/V92, wizard V93, consolidação/ações V94, telas e serviços WhatsApp manual/técnico/dossiê/diretório.
- `vendor/bin/pint --test` nos 24 arquivos PHP críticos: aprovado. Foram corrigidos três problemas de estilo/import encontrados inicialmente.
- `vendor/bin/phpstan analyse --no-progress --error-format=table`, configuração `phpstan.neon` nível 7: aprovado sem erros após correção de docblocks/tipo do objeto consultado.
- As três falhas da primeira execução do lote tinham expectativa incompatível com implementação por UUID; foram corrigidas sem reduzir cobertura. A execução repetida passou.
- `git diff --check` e inspeção de `git status --short --branch` concluídos após o lote; a árvore continua sem staged e contém alterações preexistentes preservadas.
- Testes adicionais de campanha (bounce/DSN, respostas/descadastro, recuperação IMAP isolada, follow-up, quota/reserva): **35 testes, 170 assertions aprovados**. Configuração inclui `CAMPAIGN_IMAP_ENABLED=false`, campanhas desativadas por ambiente, `Mail::fake()`/`Queue::fake()` nos cenários de escrita e nenhum acesso à caixa real.
- Após nova alteração de teste de preservação de busca/origem/página ao remover seleção, o lote combinado foi reexecutado: **96 aprovados, 4 falharam** porque `WhatsappPublicContactsV14.php` estava vazio e não autocarregava.
- O arquivo foi restaurado individualmente do backup do pré-voo, SHA-256 `ba3fb53b0ac00c2c3315b4d74b9967d0d6ebfb39064bba3809d84d96f7923689`, recuperando `class_exists`. O backup não contém a proteção recente de confirmação manual. Testes isolados após a recuperação: **4 aprovados, 1 falhou**; o teste falho reproduz o positivo técnico sendo mostrado como `yes`.
- A tentativa de reaplicar o ajuste correto com `apply_patch` foi recusada pelo sistema de arquivos (erro 1224, seção mapeada pelo usuário). Nenhum método alternativo foi usado para contornar essa trava. A suite combinada permanece reprovada e precisa ser repetida após correção.

## Retomada do bloqueio — 2026-11-13

- Pré-verificação: o arquivo atual e a cópia pré-voo têm 8.514 bytes e SHA-256 idêntico (`ba3fb53b0ac00c2c3315b4d74b9967d0d6ebfb39064bba3809d84d96f7923689`). `IsReadOnly=False`; uma abertura `FileAccess.Write` sem gravação foi permitida. `Get-Acl` e `icacls` não conseguiram obter ACLs nesta origem WSL.
- A edição pontual foi tentada uma vez e retornou erro 1224; não houve escrita parcial. `openfiles /query` foi negado e Restart Manager não retornou processo para o arquivo. Há processos `Code.exe`, mas não há evidência que permita atribuir a eles o mapeamento; o processo responsável permanece **não identificado**. Não encerrei processo nem tentei outro método de sobrescrita.
- Com ambiente explicitamente isolado (`APP_ENV=testing`, SQLite `:memory:`, `DB_URL` vazio, mailers `array`, campanhas e IMAP desativados, fila/cache/sessão em memória e cache Blade em `/tmp/prospector-audit-views-20261113`), foram executados: `WhatsappDossierProbeV91Test`, `WhatsappPublicDirectoryV14Test`, `WhatsappUnifiedV7Test`, `WhatsappVerificationV941Test`, `WhatsappWebProbeV4Test`, `WhatsappPhoneRegistryTest` e `WhatsappPublicChannelsV13Test`.
- Resultado exato: **31 passaram, 1 falhou, 0 ignorados reportados; 165 assertions**. O único erro é `WhatsappDossierProbeV91Test`, linha 42: esperado `unknown`, recebido `yes` para `evidence=web_found_unofficial`. As seis outras suítes passaram; não houve chamada real de WhatsApp, HubSpot, SMTP ou Receita. A correção não foi aplicada, então a suíte combinada não foi repetida.
- O diff do serviço continua somente com a alteração pré-existente que carrega `probe_result`/`probe_checked_at`; a tentativa bloqueada não tocou no serviço ou teste. `git diff --check` passou e não há alterações staged. Pint/PHPStan não foram repetidos, pois o código permaneceu inalterado nesta retomada.

## Estabilização das seis falhas do CI — 2026-11-13

- Ambiente: `docker compose exec -T` no serviço `laravel.test`, com `APP_ENV=testing`, SQLite `:memory:`, `DB_URL` vazio, mailers `array`, envio de campanha e IMAP desativados, fila/cache/sessão em memória e proxies HTTP(S) apontados a `127.0.0.1:9`. Blade compilado em `/tmp/prospector-ci-views-followup`. Não houve escrita no banco operacional, e-mail, chamada externa, probe real ou início de worker.
- Os seis seletores originalmente reprovados, o teste do dossiê e três suítes relacionadas foram executados juntos: **37 aprovados, 0 reprovados, 0 ignorados; 245 assertions**. Inclui `ContactsCountersPackage07Test`, `ContactsIndicatorsPackage04Test`, `ContactsReferenceV3Test`, `WhatsappWebControlV6Test`, `WhatsappWebDualSessionsV5Test`, `WhatsappDossierProbeV91Test`, `WhatsappUnifiedV7Test`, `WhatsappWebProbeV4Test` e `WhatsappPhoneRegistryTest`.
- `composer types:check`: aprovado, PHPStan sem erros.
- `composer ci:check`: código de saída 0. Pint passou em **832 arquivos**; PHPStan terminou com `[OK] No errors`; suíte completa: **1.448 aprovados, 2 ignorados, 0 reprovados (7.604 assertions; 1.450 casos contabilizados)**. Os seis grupos de falhas iniciais e `WhatsappDossierProbeV91Test` aparecem aprovados na saída integral.
- A revisão adicional do diretório correlaciona um espelho técnico legado apenas quando telefone/origem, `verified_by IS NULL` e evento `found` com o mesmo `checked_at` coincidem. Um teste cobre a preservação de uma confirmação histórica sem ator que não seja esse espelho; `totalsLean()` e `totalsAggregated()` retornam arrays idênticos.
- `git diff --check` foi executado antes da atualização documental e terminou sem erros; repetir após esta edição. A árvore continua contendo alterações não commitadas anteriores; não houve staging, commit, push ou novas exclusões.

## Lote Dashboard — interrompido por bloqueio de arquivo

- Arquivos examinados neste lote: `app/Services/DashboardOwnedDealsV23Service.php`, `tests/Feature/Dashboard/DashboardOwnedDealsSelectiveLoadV173Test.php` e `resources/views/livewire/dashboard-overview/map-interactive-v7.blade.php` (377 linhas). A leitura de `resources/css/app.css` foi limitada aos imports; seus 440.788 bytes não foram revisados integralmente.
- Referências: `map-interactive-v7.blade.php` é incluído por `map-panel-v30.blade.php`, que por sua vez é incluído por `strategic.blade.php`. Os seis CSS de versões anteriores mencionados em `ACHADOS.md` estão importados em `app.css`; os testes de Dashboard também cobrem parte desses imports. Não houve exclusão de CSS nem alteração visual.
- Ambiente dos testes: `docker compose exec -T laravel.test`, SQLite `:memory:`, `DB_URL` vazio, mailers `array`, envio de campanha e IMAP desativados, fila/cache/sessão isolados, proxy HTTP(S) em `127.0.0.1:9`. Sem banco operacional, e-mail, chamadas externas ou workers.
- Teste de regressão acrescentado para contar consultas ao catálogo. Antes da consolidação, o teste falhou como esperado: **2 consultas medidas, 1 esperada**.
- A falha original foi reproduzida: **5 reprovados, 0 assertions**, todos por `$stageLabels` indefinida. A consolidação agora compartilha mapa e rótulos carregados do mesmo catálogo; o teste de contagem confirma uma consulta. Reexecução isolada: **5 aprovados, 0 reprovados, 0 ignorados; 16 assertions**.
- PHPStan no estado atual aponta um erro restante: `missingType.iterableValue` no parâmetro opcional `$catalog` de `dealsForUser()` (linha 103). Os diagnósticos anteriores `variable.undefined` e `notIdentical.alwaysTrue` deixaram de ocorrer.
- O serviço está presente, 10.304 bytes, 270 linhas, SHA-256 `7912da8d95d459616811ea07b5851440562443f3189904edd40795d4e07430e6`. O backup da versão atual anterior a esta correção foi verificado em `/home/adolfo/backups-prospector/auditoria-20261113-dashboard-stage-catalog/` (`SHA256SUMS`).
- Uma tentativa pontual de documentar o tipo do parâmetro foi recusada pelo Windows com erro 1224 (seção mapeada). `lsof`/`fuser` não identificaram processo WSL; não houve encerramento nem sobrescrita alternativa. Não é possível concluir que o arquivo esteja desbloqueado no Windows.
- Pint `--test` e `git diff --check` passaram. PHPStan, build e CI completo ainda não estão verdes; não executar CI completo até eliminar o erro estático.

## Companies — persistência e enriquecimento parcial

- Arquivos lidos integralmente: `app/Services/CompanyService.php`, `app/Models/Company.php`, `app/Services/CompanyGroupEnrichmentService.php`, `app/Contracts/CnpjGroupDataProvider.php`, `app/Services/Providers/ReceitaLocalCnpjGroupProvider.php`, `database/migrations/2026_09_02_110539_create_companies_table.php`, `tests/Feature/Companies/CompanyServiceTest.php`, `tests/Feature/Companies/CompanyGroupEnrichmentServiceTest.php`, `tests/Feature/Establishments/EstablishmentServiceTest.php` e `tests/Feature/Integrity/CompanyDataIntegrityTest.php`. `CnpjEnrichmentService.php` foi apenas localizado por busca de chamada e não está contado como revisado.
- Atualização de cobertura em 2026-11-13: `CnpjEnrichmentService.php` foi lido integralmente no lote de providers/importações abaixo; a leitura inicial deste bloco continua sendo apenas localização.
- Ambiente isolado igual ao lote anterior: container `laravel.test`, SQLite `:memory:`, `DB_URL` vazio, mailers `array`, campanhas/IMAP desativados, fila/cache/sessão isolados e proxy HTTP(S) em `127.0.0.1:9`. A integração Receita foi coberta com provider sintético; não houve consulta externa.
- O teste novo inicialmente falhou reproduzindo persistência parcial: depois de uma matriz válida seguida por filial de outra raiz, a exceção era lançada mas uma empresa permanecia no banco de teste. `CompanyGroupEnrichmentService` passou a envolver a escrita do grupo e o cálculo ICP em uma transação; a consulta do provider continua fora da transação.
- Resultado após a correção: **16 aprovados, 0 reprovados, 0 ignorados; 60 assertions** nos quatro arquivos de teste.
- Evidências: `cnpj_root` tem índice único; processamento repetido não duplica empresa/estabelecimento; payload parcial não apaga campos já preenchidos; CNAEs autoritativos removem os antigos e deixam apenas um principal; relações podem ser adiadas durante enriquecimento de grupo.
- `phpstan analyse app/Services/CompanyGroupEnrichmentService.php`: sem erros. Pint precisou formatar uma regra de estilo; `pint --test` nos dois arquivos alterados passou após a formatação.
- O custo por CNAE permanece uma pergunta de desempenho; não houve otimização sem medição de payload representativo. A Receita real não foi consultada e o banco operacional não foi alterado.

## Leads — fila de tarefas e exportação

- Arquivos lidos integralmente: `app/Services/LeadDueTaskQueueV54Service.php`, `app/Services/LeadDueTaskExcelV54Service.php`, `app/Services/DashboardHubSpotDueTasksV40Service.php`, `app/Models/HubSpotTask.php`, `tests/Feature/Leads/LeadsHubSpotDueTasksV54Test.php` e `tests/Feature/Leads/HubSpotOverdueConsistencyV12Test.php`. Na migration `2026_09_21_150000_create_hubspot_mirror_tables.php`, foi inspecionada apenas a definição de `hubspot_tasks` e seus índices; a migration não foi executada.
- Testes dirigidos isolados: `LeadsHubSpotDueTasksV54Test`, `HubSpotOverdueConsistencyV12Test`, `LeadDueFastPathV243Test`, `LeadDueCounterReuseV242Test` e `DashboardOverdueFastPathV247Test`: **11 aprovados, 0 reprovados, 0 ignorados; 53 assertions**.
- Ambiente: container `laravel.test`, SQLite `:memory:`, `DB_URL` vazio, mailers `array`, campanhas/IMAP desativados, fila/cache/sessão em memória e proxy HTTP(S) em `127.0.0.1:9`. A suíte usa tarefas, usuários e empresas sintéticos; não consultou HubSpot nem iniciou workers.
- Evidências: a fila conta tarefas individuais mesmo sem vínculo fiscal; estados `overdue` e `today` são exclusivos no instante de vencimento; owner remoto divergente, tarefa concluída e seleção de outro proprietário não entram. O export mantém 21 colunas e neutraliza valores iniciados por caracteres de fórmula.
- Sem edição de código neste lote. O índice simples em `is_open` e `due_at` foi confirmado; não foi possível medir plano PostgreSQL/Redis. A conversão de string de data no export possui fallback silencioso para “Sem prazo”; não há reprodução com timestamp persistido válido, então fica pendente verificar a origem do dado antes de alterar o contrato.

## Companies — enriquecimento fiscal/CNAE (parcial)

- `app/Services/CnpjEnrichmentService.php` (815 linhas), `tests/Feature/Companies/RegistrationStatusAndCnaeIntegrityTest.php` e `tests/Feature/Imports/BrasilApiEnrichmentTest.php` foram lidos integralmente.
- A lógica preserva CNAEs em payload sem atividades ou malformado; só substitui a relação quando há CNAE principal válido e lista secundária presente/completa. Códigos cadastrais `2` e `02` convergem para `02`; teste também cobre CNAE com zero inicial e situação fiscal usada pelo ICP.
- Testes dirigidos: **6 aprovados, 0 reprovados, 0 ignorados; 24 assertions**. Dados BrasilAPI foram objetos sintéticos passados diretamente ao serviço; não houve chamada externa ou execução de job.
- `metadata.qsa` recebe o QSA inteiro retornado pela fonte. Busca por consumidores em `app/`, `resources/`, `public/` e `hubspot-app/` não encontrou uso; isso é apenas ausência de referência estática, não prova de dispensabilidade. Retenção/minimização e efeito em histórico precisam ser avaliados antes de excluir ou alterar.
- Sem edição ou remoção neste lote. `EnrichImportItem` e o fluxo de rede/fila da importação não foram lidos nesta fatia.

## HubSpot — paginação de tarefas e sincronização de Leads

- Arquivos lidos integralmente: `app/Services/HubSpotLeadStatusSyncService.php` (641 linhas após a correção), `tests/Feature/Companies/HubSpotLeadStatusSyncServiceTest.php` (541 linhas), `app/Support/HubSpotHttpClient.php` (330 linhas), `app/Services/HubSpotLeadStatusCandidateService.php`, `tests/Feature/HubSpot/HubSpotLeadStatusCandidateServiceTest.php`, `app/Console/Commands/SyncHubSpotLeadStatuses.php`, `tests/Feature/Companies/SyncHubSpotLeadStatusesCommandTest.php`, `app/Jobs/SyncManualHubSpotOpportunity.php`, `app/Jobs/RefreshCompanyFromHubSpot.php` e `app/Jobs/SyncHubSpotCompanyRealtimeStatus.php`. Em `routes/console.php`, foi inspecionada apenas a entrada do comando de status. O cliente HTTP não foi alterado; sua política observada repete GET por padrão, trata 429/erros transitórios e limita o atraso de `Retry-After`.
- Causa reproduzida por contrato: `associationIds()` consultava com `limit=100` mas não seguia `paging.next.after`; portanto a sincronização podia ignorar tarefas após a primeira página e subcontar tarefas abertas.
- Correção: paginação por cursor, deduplicação dos IDs retornados e exceção explícita para cursor repetido. O teste sintético fornece 100 associações na primeira página e uma na segunda, exigindo 101 tarefas abertas e cursor `after` na segunda chamada.
- Validação final: `./vendor/bin/sail artisan test tests/Feature/Companies/HubSpotLeadStatusSyncServiceTest.php` — **7 aprovados, 0 reprovados, 0 ignorados; 18 assertions**. Pint direcionado — **2 arquivos aprovados**; PHPStan direcionado ao serviço — **sem erros**; `git diff --check` — **sem erros**.
- Testes adicionais sem alteração de código: `HubSpotLeadStatusCandidateServiceTest.php` — **2 aprovados, 2 assertions**; `SyncHubSpotLeadStatusesCommandTest.php` — **2 aprovados, 5 assertions**, cobrindo saída 0 no sucesso e saída 1/`sync_error` em falha. Resultado combinado deste recorte: **11 testes aprovados, 25 assertions**.
- A entrada em `routes/console.php` agenda `hubspot:lead-statuses` a cada cinco minutos, com `onOneServer()` e `withoutOverlapping()`. O scheduler e os jobs não foram iniciados. As três classes de job foram apenas lidas; seus fluxos de execução/recuperação e testes ainda precisam de auditoria própria.
- Isolamento: execução pelo container `laravel.test`, banco SQLite `:memory:` conforme o ambiente de teste já verificado; o caso novo usa `Http::fake()` para todas as respostas HubSpot. Não houve escrita HubSpot, chamada externa, worker ou alteração operacional.
- Limitação: `openTasks()` ainda faz um GET por tarefa; a contagem de chamadas e o benefício de batch-read não foram medidos em API real. Não foi feita otimização especulativa. CI completo não foi repetido porque o lote Dashboard permanece reprovado/bloqueado.

## HubSpot — atomicidade do status e da auditoria — 2026-11-13

- Causa: o serviço capturava o estado anterior antes da consulta externa e registrava `company_lead_activities` após a transação do status. Uma falha de auditoria podia confirmar uma mudança comercial sem histórico correspondente, e o estado “anterior” podia estar defasado sob concorrência.
- Correção em `app/Services/HubSpotLeadStatusSyncService.php`: relê o lead com `lockForUpdate()`, calcula a transição a partir desse snapshot e grava status e atividade dentro da mesma transação.
- Regressão em `tests/Feature/Companies/HubSpotLeadStatusSyncServiceTest.php`: trigger SQLite faz a inserção de `hubspot_status_changed` falhar; o teste exige a exceção `QueryException` e confirma rollback do status para `new`.
- Validação isolada pelo container `laravel.test`, SQLite `:memory:`, `DB_URL` vazio, mail/cache/sessão em array, fila sync, token HubSpot fictício e proxy local de bloqueio; respostas HubSpot são fakes. Suíte combinada (status, candidatos, command, retry HTTP e timeline): **20 aprovados, 0 reprovados, 0 ignorados; 53 assertions**.
- Pint nos dois arquivos alterados, PHPStan no serviço e `git diff --check`: aprovados. Nenhuma chamada externa, worker, scheduler, escrita HubSpot, migration ou alteração operacional.
- Limitação: o endpoint ainda faz uma leitura por tarefa; 101 tarefas sintéticas percorrem 101 leituras individuais, mas o teste não mede latência/rate limit real. Batch-read e perfil representativo permanecem pendentes. O CI completo não foi repetido por causa do bloqueio/falha seletiva preexistente do Dashboard.

## HubSpot — sincronização do espelho e associações — 2026-11-13

- Revisão integral de `app/Services/HubSpotWebhookMirrorSyncService.php` e `tests/Feature/HubSpot/HubSpotWebhookMirrorSyncTest.php`; também foram lidos `ProcessHubSpotWebhookEvent.php`, `HubSpotWebhookEvent.php`, `HubSpotWebhookEventContextService.php`, `HubSpotDealCompanyAssociationService.php`, `HubSpotKnownCompanyLinkService.php` e o command `ReconcileHubSpotCommercialV40.php`. A migration de pivôs foi consultada apenas para localizar chaves únicas/índices, não foi executada nem lida integralmente.
- Reprodução pré-correção: **3 testes existentes passaram e os 3 novos falharam** (19 assertions executadas). O caso 100+1 deixou só 100 contatos associados; resposta sem `results` não lançava erro e apagava o pivô conhecido; HTTP 503 de pipelines era aceito e gravava o ID do estágio como rótulo.
- Correção aplicada em `app/Services/HubSpotWebhookMirrorSyncService.php`: paginação por `after` para associações; deduplicação; erro explícito para JSON/estrutura de `results` e cursores inválidos/repetidos; endpoint de pipeline agora usa o tratamento HTTP comum e valida `results` antes de persistir dados.
- Validação pós-correção: suites de mirror, placeholders de tarefa e webhook (assinatura, retry, entrega e recuperação), SQLite `:memory:`, `Http::fake()`, proxy local de bloqueio e token fictício: **22 aprovados, 0 reprovados, 0 ignorados; 88 assertions**. Não houve acesso externo, worker, scheduler, escrita HubSpot ou alteração operacional.
- Pint `--test` nos dois arquivos e PHPStan direcionado ao serviço passaram; o Pint foi aplicado ao teste. `git diff --check` passou. Backup externo pré-edição verificado em `/home/adolfo/backups-prospector/auditoria-20261113-webhook-mirror-pagination/`, manifesto `SHA256SUMS`.
- Limite ainda aberto: um refinamento para falhar em HTTP 404 após a primeira página e em cada item de associação malformado recebeu Windows 1224. Restart Manager listou processos Git de curta duração no recurso, mas não determinou a causa; `fuser`/`lsof` não reportaram processos WSL. Nenhum processo foi encerrado ou método alternativo de escrita usado. Até liberar o arquivo, respostas com linha individual malformada ou 404 numa página posterior podem ainda ser interpretadas como ausência parcial de associações. O resolver independente permanece pendente em A-27.

## HubSpot — integridade de associações da Primary Company — 2026-11-13

- Revisados integralmente `app/Services/HubSpotDealCompanyAssociationService.php` (671 linhas), `tests/Feature/HubSpot/HubSpotPrimaryDealCompanyTest.php` (683 linhas) e `app/Console/Commands/SyncHubSpotDealPrimaryCompanies.php` (222 linhas); também foram consultados os serviços de espelho/contexto e o contrato do pivô. Não foi encontrado teste chamando diretamente o command, e ele não foi executado.
- Causa: respostas sem a estrutura `results`, linhas inválidas e cursor repetido podiam ser aceitos como uma fotografia parcial/vazia. A sincronização subsequente substitui os pivôs, com risco de perder associações locais. Um 404 depois da primeira página também era confundido com ausência de associações.
- Reprodução pré-correção: três regressões novas falharam e os três cenários existentes passaram. Correção: validar `results`, IDs e `paging.next.after`; rejeitar cursor repetido; aceitar 404 somente na primeira página e propagar erro de página posterior antes de sincronizar pivôs. Os testes confirmam preservação dos vínculos existentes nos três erros.
- Resultado: teste isolado Primary Company **6 aprovados, 0 reprovados, 0 ignorados; 19 assertions**. Suíte combinada de mirror, placeholders e webhooks **28 aprovados, 0 reprovados, 0 ignorados; 107 assertions**. Configuração isolada: container `laravel.test`, SQLite `:memory:`, `Http::fake()`, proxy local de bloqueio e token HubSpot fictício; sem chamadas externas, workers, scheduler ou gravações operacionais.
- Pint `--test` nos quatro arquivos tocados/relevantes passou; PHPStan nos dois serviços de produção passou; `git diff --check` passou. PHPStan incluindo o arquivo de teste ainda reporta dois diagnósticos preexistentes `nullsafe.neverNull` nas asserções existentes (linhas 349 e 357); não alterados para evitar ampliar o escopo.
- Backup pré-edição verificado em `/home/adolfo/backups-prospector/auditoria-20261113-hubspot-primary-cursor/`. CI completo permanece pendente devido à falha conhecida do Dashboard; o command de sincronização e o resolver de associações do webhook ainda precisam de testes/auditoria próprios.

## HubSpot — mirror de atividades — 2026-11-13

- Revisados integralmente `app/Services/HubSpotActivitySyncService.php` (1.247 linhas) e `tests/Feature/HubSpot/HubSpotActivitySyncServiceTest.php` (510 linhas); lidos os pontos de chamada do job de webhook, serviço de vínculo fiscal e command de reconstrução, além dos modelos de atividade. Job/command/service chamadores não foram revisados integralmente nesta fatia.
- Baseline isolada: **3 testes/15 assertions passaram**. A nova reprodução de duas respostas — primeiro conteúdo válido, depois HTTP 200 com `properties` string — falhou como esperado porque nenhuma exceção era lançada e o fluxo aceitava substituir o mirror com conteúdo vazio.
- Correção: `readActivity()` exige o campo `properties` e valida que seja array; resposta malformada agora lança `RuntimeException` antes de atualizar o mirror ou a projeção fiscal. O teste verifica que os dois registros conservam a descrição anterior.
- Suíte combinada `HubSpotActivitySyncServiceTest`, `HubSpotUnmatchedActivitySyncTest` e `HubSpotLongActivityTitlesV39Test`: **9 aprovados, 0 reprovados, 0 ignorados; 47 assertions**. Isolamento: `laravel.test`, SQLite `:memory:`, `Http::fake()`, proxy local de bloqueio e token fictício. Sem API real, worker, scheduler, banco operacional ou command de reconstrução.
- Pint `--test` nos dois arquivos, PHPStan do serviço e `git diff --check` passaram. Backup pré-edição verificado em `/home/adolfo/backups-prospector/auditoria-20261113-hubspot-activity-validation/` via `SHA256SUMS`.
- Reexecução combinada das suítes de associação, webhook e atividade após as duas correções HubSpot: **37 aprovados, 0 reprovados, 0 ignorados; 154 assertions**. Pint nos quatro arquivos e PHPStan nos dois serviços de produção passaram.

## HubSpot — vínculo fiscal e promoção de atividades — 2026-11-13

- Revisados integralmente `app/Services/HubSpotCompanyLinkService.php` (330 linhas), `tests/Feature/HubSpot/HubSpotCompanyLinkServiceTest.php` (358 linhas), `app/Models/HubSpotCompany.php` (337 linhas) e a migration de `hubspot_activities`/pivô (142 linhas).
- A gravação fiscal exige vínculo manual confiável, bloqueia mudança de Company já vinculada, e atualiza espelho CRM, projeção comercial e estado de trabalho dentro da mesma transação. As regras do modelo impedem que fontes de associação consideradas inseguras voltem a atribuir CNPJ por propagação. O escopo `trustedFiscalLink` usa o mesmo padrão de origem que a guarda PHP.
- Teste isolado de vínculo: **3 aprovados, 0 reprovados, 0 ignorados; 23 assertions**, incluindo importação via resposta sintética da Receita local, proteção contra troca de vínculo e atualização de projeções. PHPStan do serviço passou. Sem chamadas reais, banco operacional, command ou job.
- Sem nova regressão reproduzida; nenhum código alterado nesta fatia. A promoção de atividade já é exercitada diretamente em `HubSpotUnmatchedActivitySyncTest`; ainda não há teste que combine essa promoção e `HubSpotCompanyLinkService::link()` no mesmo cenário. A associação de owner depende de comparação por nome único entre usuários verificados; sem benchmark, não foi otimizada. O custo da transação do fluxo de importação/enriquecimento permanece não medido.

## HubSpot — resolução e envio de contatos — 2026-11-13

- Revisados integralmente `HubSpotContactResolverService.php` (505 linhas), seu teste (387), `HubSpotContactCandidateService.php` (385) e teste (232), `HubSpotCompanyContactSyncService.php` (171), `HubSpotAllCompanyContactsSyncTest.php` (361), `HubSpotAssociationService.php` (149) e teste (117).
- Baseline: resolver e candidatos passaram (**4 testes/29 assertions**); teste de envio por empresa passou em execução posterior. As novas reproduções falharam antes do fix: busca HTTP 200 sem `results` permitia criar contato, e GET de contato com `properties` string permitia seguir para PATCH.
- Correção no resolver: estrutura `results`, IDs de cada registro e `properties` da leitura agora são validados; resposta inválida interrompe a sincronização explicitamente antes de POST/PATCH. O caso legítimo `results: []` continua permitindo criar novo contato. Teste também cobre ID ausente em uma linha da busca.
- Validação final dos testes de resolver/candidatos/envio: **8 aprovados, 0 reprovados, 0 ignorados; 43 assertions**. Serviço de escrita de associações: **3 aprovados; 6 assertions**, incluindo ausência de retry para PUT HTTP 500. Pint e PHPStan direcionados passaram; `git diff --check` passou.
- HTTP foi interceptado por `Http::fake()`, banco SQLite `:memory:`, token fictício e proxy local de bloqueio; sem envio real, worker ou alteração operacional. Backup do resolver/teste antes da edição verificado em `/home/adolfo/backups-prospector/auditoria-20261113-hubspot-contact-validation/`.
- Pendências: revisar se corpos de resposta incluídos em exceções (limitados a 1.000 caracteres) podem carregar dados pessoais; confirmar comportamento de busca por telefone quando a formatação remota difere; não foi feito acesso real nem benchmark de limites/API.
- Execução final combinada das fatias HubSpot de mirror, associação, webhooks, atividades e contatos: **48 aprovados, 0 reprovados, 0 ignorados; 203 assertions**. Pint passou em 10 arquivos; PHPStan passou nos cinco serviços HubSpot revisados/corrigidos. O erro PHPStan isolado do Dashboard está fora desse grupo e permanece pendente.

## HubSpot — reconstrução de projeções — revisão sem execução

- `app/Console/Commands/RebuildHubSpotActivityProjections.php` foi lido integralmente (121 linhas); busca em `tests/` não encontrou teste específico para `hubspot:rebuild-activity-projections`.
- Risco observado: o command remove primeiro todas as projeções `source=hubspot` e só depois percorre empresas/atividades com `chunkById(100)`. Uma falha a meio deixa a projeção apagada/parcial. `--dry-run` informa apenas a contagem; não valida a reconstrução.
- Não executei o command nem o modo que altera dados. Sem patch/teste nesta fatia; A-37 fica pendente de estratégia segura de staging/publicação atômica e teste com falha injetada. A consulta de atividades por empresa também não foi perfilada.
- Pendências: revisar o command de reconstrução e recuperação de projeções integralmente; testar falhas/retries da leitura de atividade; avaliar tamanho/retention de `raw_properties` (pode conter corpos de e-mail) com política de negócio antes de propor minimização. CI completo segue pendente por falha seletiva no Dashboard.

## HubSpot — entrada e recuperação de webhooks (fatia parcial)

- Arquivos lidos integralmente: `HubSpotWebhookController.php`, `HubSpotWebhookSignatureVerifier.php`, `VerifyHubSpotWebhookSignature.php`, `HubSpotWebhookQueueService.php`, `ProcessHubSpotWebhookEvent.php`, `HubSpotWebhookEvent.php`, migration `2026_09_24_093000_create_hubspot_webhook_events_table.php` e quatro testes de assinatura/retry/recovery. Em `routes/web.php`, apenas a rota `/webhooks/hubspot` foi inspecionada; em `routes/console.php`, foram lidas as entradas relacionadas à recuperação.
- Evidência na fatia: rota aplica middleware de assinatura antes do controller; o verificador confere HMAC v3, timestamp dentro de cinco minutos e URL pública configurável. `event_key` tem índice único. A reserva é persistida antes do dispatch, o job reivindica o evento sob transação/lock, estados terminais não são retomados e recovery aplica limites mínimos de idade, rechecados sob lock.
- Execução combinada dos testes isolados `HubSpotWebhookSignatureAndRetryTest`, `HubSpotWebhookJobRetryTest`, `HubSpotWebhookRetryDeliveryTest` e `HubSpotWebhookRecoverySafetyV10Test`: **13 aprovados, 0 reprovados, 0 ignorados; 50 assertions**. Filas foram fake; o teste de retry de job usa evento `unknown` e não chama serviços externos. Nenhum worker/scheduler foi iniciado.
- Sem correções no fluxo de entrada/retry desta fatia. A migration foi lida, não executada. `HubSpotWebhookMirrorSyncService.php` e `HubSpotWebhookEventContextService.php` foram revisados em lote posterior (ver seção acima); `HubSpotWebhookAssociationResolver.php`, outros comandos de reparo e política de retenção/acesso ao payload continuam pendentes.
- O controller conta elementos inválidos e ainda responde HTTP 202 para o lote; a política para lote parcialmente inválido não foi alterada nem considerada defeito sem confirmação do contrato de retry do HubSpot. CI completo continua pendente por causa do Dashboard.

## HubSpot — resolução de associações no webhook (pendente)

- `HubSpotWebhookAssociationResolver.php` (827 linhas), `HubSpotWebhookAssociationResolverTest.php` e `HubSpotWebhookObjectTypeService.php` foram lidos. O resolver usa `limit=100` em `associationIds()` mas não percorre `paging.next.after`, o mesmo padrão de truncamento confirmado e corrigido no sincronizador de status. `HubSpotWebhookObjectTypeService` mapeia task/note/call/email/meeting para os nomes de API usados nesse caminho.
- A primeira tentativa de adicionar uma reprodução sintética 100+1 foi aplicada dentro do callback de outro teste. A execução reportou somente **1 teste aprovado, 3 assertions**; o novo caso não foi registrado e, portanto, não conta como validação. Uma correção pontual foi recusada pelo Windows com erro 1224 no arquivo de teste. O serviço de produção permaneceu inalterado; não declarar esta correção como feita.
- Diagnóstico de bloqueio read-only: Windows Restart Manager retornou zero processos para o teste do resolver e para o serviço Dashboard; `handle.exe` não está disponível. O processo responsável não foi identificado. Nenhum processo foi encerrado nem se tentou sobrescrita alternativa.
- Próximo passo quando liberado: mover o caso para nível superior do arquivo de teste, executar primeiro para reproduzir o truncamento, implementar paginação com cursor no resolver e executar a suíte de associação e os testes do webhook sem rede real.

## Limpeza e recuperação

- Backup suplementar preserva 69 marcadores `Zone.Identifier` e o arquivo `nul`, com caminho relativo, tamanho, modo e SHA-256; as cópias foram comparadas com as origens antes da retirada.
- A retirada foi limitada aos 70 caminhos do manifesto. Verificação posterior encontrou zero marcadores no projeto e confirmou a ausência de `nul`.
- `.gitignore` passou a ignorar `*:Zone.Identifier` e `/nul`; sua versão anterior foi arquivada externamente.
- Git mostrou 36 exclusões rastreadas adicionais não incluídas no manifesto. Após autorização do usuário, esses 36 arquivos foram restaurados individualmente do HEAD; as três exclusões intencionais permaneceram.
- Um `.git/index.lock` vazio foi removido após confirmação de que não havia processo Git ativo, para permitir a restauração. Não houve alteração de índice/staging.
- Os relatórios anteriores de `docs/auditoria/` não estavam no backup; estes arquivos foram reconstruídos, e `INVENTARIO.csv` regenerado.
- Validação estrutural do inventário: 1.764 registros, zero caminhos duplicados, 69 marcadores removidos e uma entrada para `nul`. `git check-ignore --no-index` confirmou as regras `*:Zone.Identifier` e `/nul`.
- `git diff --check` passou após as alterações finais. Verificação adicional dos sete arquivos novos de auditoria encontrou zero espaços finais ou arquivos sem newline final.

## Não executado / pendente

- Build Vite e validação visual/navegador.
- Testes Node além do classificador; instalação de dependências não realizada.
- Navegador/E2E, console/rede, validação visual, responsiva e acessibilidade.
- PostgreSQL/Redis e concorrência real; testes de estado usaram SQLite isolado.
- Renderização integral de Blade, rotas completas, integrações reais HubSpot/SMTP/WhatsApp/Receita, filas e banco operacional — intencionalmente não executadas.
- Revisão integral dos módulos, arquivos próprios, arquivos ignorados, dependências e resíduos.

## Imports — job de enriquecimento e isolamento de testes

- Arquivos lidos integralmente neste lote: `app/Jobs/EnrichImportItem.php` (412 linhas), `tests/Feature/Imports/EnrichmentQueueTest.php` (881 linhas), `app/Services/ImportQueueService.php` e `app/Services/ExportResearchQueueService.php`. `app/Services/CrmCheckService.php`, `phpunit.xml` e `tests/TestCase.php` também foram lidos. De `HubSpotCrmCompanyProvider.php`, foram examinados apenas o ponto de entrada `findCompany()` e a guarda `assertConfigured()` para confirmar a dependência de rede; o provider não foi revisado integralmente.
- O fluxo do job tenta a fonte local Receita, usa `CnpjNotFoundException` para ativar o provider pontual, repropaga `CnpjProviderTemporaryException` para retry, registra falhas permanentes, atualiza CRM em bloco isolado e sempre recalcula o lote no `finally`. O serviço de fila limita despacho por chunk/teto, e a recuperação seleciona itens estagnados por idade. Nenhuma regressão foi reproduzida no job; a otimização separada de `CnpjImportService` está documentada abaixo.
- A primeira execução da suíte passou (10 testes/52 assertions), mas não tinha bloqueio HTTP explícito. Como `CrmCompanyProvider` é ligado por padrão ao HubSpot e alguns casos executam o job sincronamente sem mock desse provider, não é possível determinar retrospectivamente se houve tentativa ou leitura externa. A chamada desse job é de consulta; não há evidência de escrita HubSpot. Esse resultado não é contado como teste isolado seguro.
- Para impedir repetição do risco, `EnrichmentQueueTest.php` agora configura token HubSpot fictício, desativa `prospector.export_research.enabled` e chama `Http::preventStrayRequests()` em `beforeEach`. Os providers de Receita usados pelos cenários de execução são fixtures locais; os cenários que verificam CRM substituem o provider por fake. Os cenários de despacho usam `Queue::fake()`.
- Reexecução após a proteção: **10 aprovados, 0 reprovados, 0 ignorados; 52 assertions**. Configuração: container `laravel.test`; `phpunit.xml` fixa `APP_ENV=testing`, SQLite `:memory:`, `DB_URL` vazio, mailer `array`, fila/cache/sessão em memória; comando também remove token HubSpot do processo, desativa pesquisa externa e aponta HTTP(S) proxy para `127.0.0.1:9`. A proteção `Http::preventStrayRequests()` bloqueia ainda chamadas pela facade independentemente do proxy. Nenhuma escrita em CRM, pesquisa externa, e-mail ou banco operacional foi executada nessa repetição.
- Backup antes da alteração do teste: `/home/adolfo/backups-prospector/auditoria-20261113-import-test-isolation/`; manifesto externo `SHA256SUMS`, hash verificado antes da edição.
- `EnrichImportItem.php` e `EnrichmentQueueTest.php` agora têm cobertura integral registrada no inventário existente; ele continua com **1.764 registros de arquivos** (1.765 linhas incluindo cabeçalho). A validação encontrou quatro notas preexistentes com vírgulas não escapadas; foram normalizadas com ponto e vírgula. CSV validado: 11 colunas em todas as linhas e zero caminhos duplicados.
- Pint no teste alterado: aprovado. `git diff --check` será repetido após esta atualização documental.
- Imports permanece parcial: páginas, validação de uploads, comando completo, resolução/retenção de QSA, limites de provider e comportamento em fila Redis/PostgreSQL não foram auditados. A suíte ampla/CI não foi executada por causa da regressão bloqueada do Dashboard já registrada.

## Imports — consulta de estabelecimentos em lote

- Arquivos examinados integralmente neste recorte: `app/Services/CnpjImportService.php`, `tests/Feature/Imports/CnpjImportServiceTest.php`, `database/migrations/2026_09_02_193640_create_import_items_table.php`, `database/migrations/2026_09_16_114650_add_cnpj_root_to_import_items_table.php`, `database/migrations/2026_09_02_110541_create_establishments_table.php` e `app/Models/Establishment.php`. As migrations foram lidas, não executadas.
- Medição antes da correção: teste sintético com 40 CNPJs válidos e únicos falhou ao exigir no máximo um lookup: foram observadas **40 consultas SELECT** à tabela `establishments`, uma por linha.
- Correção em `CnpjImportService::import()`: pré-consulta exata de CNPJs válidos em blocos de 500 parâmetros; o índice único de `establishments.cnpj` confirma uma linha por CNPJ. A classificação continua em ordem de entrada: espaços são ignorados sem criar item; duplicatas internas não se tornam `existing`; itens já cadastrados preservam `company_id`; raiz e `row_number` continuam sendo gravados no item.
- Medição depois da correção, para o mesmo cenário: **1 lookup SELECT**, redução de 40 para 1 (97,5%). O resultado é contagem de consultas em SQLite de teste, não benchmark de latência nem plano PostgreSQL. Em 10.000 linhas válidas o desenho limita os lookups a até 20 lotes, ainda sem medir tempo em volume máximo.
- Regressão adicional confirma primeiro item existente e segundo duplicado com `company_id` preservado. Execução isolada combinada de `CnpjImportServiceTest`, `EnrichmentQueueTest`, `ImportPagesTest` e `RunProspectingCommandTest`: **25 aprovados, 0 reprovados, 0 ignorados; 109 assertions**. Pint nos dois arquivos modificados passou; PHPStan em `CnpjImportService.php` não encontrou erros.
- Ambiente da execução: SQLite `:memory:`, `DB_URL` vazio, mailer `array`, fila/cache/sessão em memória, token HubSpot vazio, pesquisa externa desativada e proxies HTTP(S) locais apontados à porta 9. `RunProspectingCommandTest` usa resposta `Http::fake()` e fila fake; nenhuma execução real de prospecção ocorreu.

## Imports — interface, visibilidade e CSS

- Arquivos lidos integralmente: `resources/views/pages/imports/⚡index.blade.php` (762 linhas), `resources/views/partials/imports/figma-v65.blade.php` (324 linhas), `resources/css/imports-figma-consolidated.css`, `tests/Feature/Imports/ImportPagesTest.php`, `ImportRedesignTest.php`, `ImportsWorkspaceUiTest.php`, `ImportsFigmaV65Test.php` e `ImportsFigmaContrastV652Test.php`. `EnsureCommercialWorkspace.php` e `EnsureCommercialManager.php` foram lidos; em `routes/web.php` foi examinada apenas a entrada `/importacoes` e seu middleware.
- O fluxo da UI separa validação de enfileiramento; a ação de fila é explícita, enquanto o polling de um lote ativo chama `recoverStale()` para recuperar itens parados. A tabela consulta até 100 registros por pesquisa e faz paginação cliente-side sobre esse conjunto; o texto da interface expõe esse limite. Os links de empresa seguem para o dossiê.
- `commercial.workspace` autoriza gestores e vendedores. O histórico é global no workspace e `openBatch()` aceita o ID do lote sem filtro `user_id`. Pode ser decisão intencional de colaboração; política/expectativa não ficou documentada nem testada por usuário cruzado. Não foi aplicado filtro: pendência A-30.
- Desempenho pendente A-31: `hasMoreHistory` faz `COUNT(*)` sem filtro em toda renderização, e o polling pode renderizar a cada dois segundos; buscas usam curingas com o lote todo antes do `limit(100)`. Não houve perfil/benchmark PostgreSQL; não foi alterado.
- `app.css` importa `imports-figma-consolidated.css` uma vez (linha 18069); o teste V65.2 confere esse import e os seletores de contraste. A folha concentra estilos I01/I02 e overrides de contraste escopados à página. Sem remoção ou mudança visual; `:has()` e responsividade ainda precisam de browser real.
- Testes dirigidos de layout/workspace/contraste/redesign: **13 aprovados, 0 reprovados, 0 ignorados; 84 assertions**. Somados às suites de processamento/importação já registradas, esta etapa executou **38 testes aprovados e 193 assertions**; esses números são dos lotes dirigidos, não da suíte completa.
- Nenhum teste de navegador foi executado. `ImportsWorkspaceUiTest`, `ImportsFigmaV65Test`, `ImportsFigmaContrastV652Test` e `ImportRedesignTest` foram executados com SQLite em memória, filas fake, HTTP stray bloqueado/proxy local, sem worker, sem rede externa e sem mutation operacional.

## Imports e HubSpot — providers CNPJ e importação CSV

- Arquivos lidos integralmente: `app/Services/Providers/BrasilApiCnpjProvider.php`, `ApiBrasilCnpjProvider.php`, `app/Services/CnpjEnrichmentService.php` (815 linhas), `app/Services/HubSpotExportImportService.php` (2.369 linhas após a correção), `app/Console/Commands/ImportHubSpotExports.php`, `tests/Feature/Imports/BrasilApiCnpjProviderTest.php`, `ApiBrasilCnpjProviderTest.php` e `tests/Feature/HubSpot/HubSpotExportImportTest.php`. `HubSpotCompany.php` e `HubSpotImportRun.php` foram lidos integralmente; da migration `2026_09_21_150000_create_hubspot_mirror_tables.php` foram lidos trechos de esquema, sem executá-la operacionalmente (o `RefreshDatabase` construiu o esquema de teste em SQLite `:memory:`).
- `BrasilApiCnpjProvider` é o binding efetivo de `CnpjDataProvider`; trata erro de conexão e 429/5xx como temporários e 404 como não encontrado. `ApiBrasilCnpjProvider` não tem call site de produção encontrado em PHP: há a classe, configuração e teste direto, mas não está ligada no `AppServiceProvider`; foi mantida, sem presumir que é dispensável. Seu teste cobre apenas chamada de sucesso, portanto tratamento de rate limit/retry continua sem contrato validado.
- Causa A-32: antes da edição, `--replace` limpava as tabelas `hubspot_*` e o histórico antes de resolver arquivos ou validar cabeçalhos. Caminho/ZIP/header inválido podia apagar o snapshot anterior. Agora os quatro arquivos e cabeçalhos obrigatórios são verificados antes da limpeza; falha de pré-validação registra `failed` sem limpar o espelho. O teste de sucesso também exercita `replace: true` e substituição do snapshot anterior.
- A primeira execução após mover a criação do registro para depois da pré-validação encontrou referência residual a `$run->uuid` no caminho temporário. Corrigida para usar o UUID já preparado; o rerun dos mesmos três arquivos passou.
- A tentativa seguinte de tornar todo `--replace` transacional foi bloqueada ao salvar pelo Windows (erro 1224). Hash de leitura permaneceu `600b1d0ad404fa1184032ac4c41c6ea16f2904e3ad9b7fa23eef432c6b305b67`; nenhum processo foi encerrado nem se tentou caminho alternativo de escrita. A atomicidade permanece pendente.
- Validação pós-alteração: `HubSpotExportImportTest.php`, `BrasilApiCnpjProviderTest.php` e `ApiBrasilCnpjProviderTest.php`: **6 aprovados, 0 reprovados, 0 ignorados; 30 assertions**. Pint em dois arquivos passou; PHPStan direcionado ao serviço sem erros; `git diff --check` passou.
- Isolamento: SQLite `:memory:`, `DB_URL` vazio, mailer/fila/cache/sessão em memória, token HubSpot vazio, pesquisa externa desativada e proxies HTTP(S) locais bloqueadores. Testes dos providers usam `Http::fake()`; importação usa CSV sintético. Não foi chamado o comando Artisan operacional, não foram lidos exports reais nem executadas migrations contra banco operacional.
- Limite ainda relevante: os writes após a pré-validação não estão em uma transação única; erro de banco durante a substituição pode deixar espelho parcial. Não executar `--replace` em dados reais até implementar/verificar atomicidade com PostgreSQL. O custo de manter muitas entidades/associações em memória no serviço grande também não foi medido; requer carga sintética representativa, sem export comercial.

## Testes ignorados no CI — motivo confirmado

- `config/fortify.php` não inclui `Features::registration()`. `RegistrationTest.php` chama `skipUnlessFortifyHas(Features::registration())`, e `tests/TestCase.php` marca os testes como ignorados quando a feature está desabilitada.
- Execução isolada: **2 skipped, 0 assertions**, motivos explícitos “Fortify feature [registration] is not enabled” nos testes `registration screen can be rendered` e `new users can register`.
- A omissão corresponde à política comentada de registro aberto desativado; não são testes funcionalmente aprovados, e não há indicação de regressão. A cobertura da política de convite/criação aprovada de usuários não foi avaliada nesta fatia.
