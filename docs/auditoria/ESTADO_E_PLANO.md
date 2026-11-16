# Estado e plano da auditoria

## Fonte da verdade e preservação

- Checkout: `/home/adolfo/projetos/prospector-exportcontrol`.
- Branch `main`; HEAD `7a0ff6d62fb3ed6ca18e9b68c3d236452807d86d`, igual à referência informada.
- O backup inicial preservou 181 caminhos do estado sujo em `/home/adolfo/backups-prospector/auditoria-20261112-204025/`, total de 3.709.764 bytes; manifesto SHA-256 `9f5b75d6056fa4f4b8552bd308bf72f3ab117f5dac2870a2d8946a343266e020`.
- Cópias suplementares anteriores às edições: `CampaignManager.php` SHA-256 `18b745197abe48bbc9a7a6e6d0e75860d68e13fcd692e874f0c4c03d9df00597`; `CampaignAudience.php` SHA-256 `a9561c4e2ad2a9757c33409e8c86ec22700c241f148b1ec27ac8e23e72f60a0a`.
- Os 69 arquivos `:Zone.Identifier` e o arquivo raiz vazio `nul` foram copiados antes da remoção individual. Backup suplementar: `/home/adolfo/backups-prospector/auditoria-20261112-204025/supplemental/zone-identifiers-and-nul/`; manifesto SHA-256 `6e62f8290832b0c11634cee839b9998664f87ee1df5c19a6aef683b4d1189a5e`. A cópia anterior de `.gitignore` tem SHA-256 `3b085cb55828cebace25c1cbedf64621c0dd8f8d281504d9c60ae75da8b56217`.
- Durante a limpeza surgiu um conjunto inesperado de 36 exclusões rastreadas fora do manifesto. Após decisão do usuário, os 36 caminhos foram restaurados individualmente do HEAD e verificados. Permanecem removidos somente `nul` e os dois marcadores rastreados; nenhum outro conteúdo foi restaurado globalmente.
- O diretório `docs/auditoria/` também deixou de estar presente e não constava do backup externo. Seus relatórios foram reconstruídos a partir do registro desta sessão. A versão anterior do inventário, com 1.834 registros, não estava recuperável; o CSV atual foi regenerado com 1.764 registros de metadados (1.496 caminhos rastreados, arquivos alterados/novos e agregados protegidos) e não deve ser interpretado como cobertura integral.
- Nenhum commit/push, envio, escrita HubSpot, varredura WhatsApp, migration operacional, deploy, worker ou scheduler foi executado.

## Baseline e progresso

- Baseline inicial: 21 testes passaram e 2 renderizações falharam pelo caminho ausente do cache Blade/Livewire Blaze. Com `VIEW_COMPILED_PATH` temporário: 23 testes e 112 assertions passaram.
- Seleção de contatos, snapshots de e-mail e transferência para campanha foram corrigidos/testados; também validação V91, confirmação manual WhatsApp, classificação inconclusiva e transições de campanha.
- `CampaignAudience::resolve()` passou a consultar contatos e supressões em lote. Teste sintético com 20 destinatários verifica duas consultas.
- Continuação de 2026-11-13: o wizard HTTP V93 agora mantém estado por UUID/aba e os pontos de entrada Nova campanha, modelos, edição e transferência V91/V92 convergem para ele. Atualizados testes que ainda esperavam estado de sessão compartilhado.
- Estado atual do WhatsApp: `found` promove somente o índice global para `yes` técnico (`web_found_unofficial`) e registra evento; não cria confirmação manual em `contact_phone_verifications`. `not_found`, `inconclusive` e `error` não comprovam ausência. Contatos preserva confirmações históricas sem ator, excluindo do status manual apenas o espelho que coincide com evidência/evento técnico. O dossiê mantém `unknown` para o positivo técnico e conserva evidência e probe.
- Validação dirigida inicial, com SQLite `:memory:`, DB_URL vazio, mailers `array` e campanha desativada, fila/cache/sessão isolados: 64 testes e 388 assertions aprovados; 35 testes/170 assertions adicionais de respostas, bounces e follow-ups também passaram.
- Histórico do bloqueio: `WhatsappPublicContactsV14.php` chegou a 0 bytes, foi recuperado do backup e houve uma tentativa de edição recusada pelo Windows 1224; o processo responsável não foi identificado. Esse registro é histórico, não o estado funcional atual: o arquivo presente mantém a origem técnica separada e `WhatsappDossierProbeV91Test` passa.
- A primeira execução isolada anterior às correções do CI teve 31 aprovações e 1 falha no dossiê; esse resultado foi substituído pelas execuções abaixo, sem alteração de evidências históricas.
- `composer types:check` passou e o `composer ci:check` completo passou após as correções: Pint em 832 arquivos, PHPStan sem erros, 1.448 testes aprovados, 2 ignorados, 0 reprovados e 7.604 assertions. Os nove seletores dirigidos passaram: 37 testes e 245 assertions.
- As três falhas iniciais desta continuação eram contratos de teste desatualizados: conteúdo fora da página procurado no HTML, sessão de wizard compartilhada e expectativa de 200 antes de seguir o redirect legado. Ajustadas para verificar snapshot por UUID e destino final; sem remover cobertura.
- A auditoria permanece **parcial**. Código próprio, arquivos ignorados, assets, documentação histórica e integrações não foram todos revisados integralmente. Não declarar homologação funcional nem cobertura integral.

## Estabilização do CI — 2026-11-13

- Causa raiz: requisitos recentes de `verified_by` excluíam confirmações históricas de Contatos sem ator, zerando quatro indicadores; a remoção de `promote()` deixava `found` apenas no resultado da consulta e mantinha o índice global `unknown` em dois testes.
- Correções nesta etapa: `ContactPeopleDirectory.php` preserva o contrato histórico e só filtra espelho técnico quando fonte/telefone e evento `found` com o mesmo timestamp correspondem; `WhatsappWebConfirmationService.php` promove somente resultado técnico elegível, sob lock, sem sobrescrever qualquer decisão humana e sem gravar no diretório de confirmação manual; `WhatsAppWebProbeRecord.php` chama a promoção apenas para `found`; `WhatsappPhoneRegistry.php` expõe `web_found_unofficial` como positivo técnico, não como humano.
- Regressões cobertas em `WhatsappUnifiedV7Test.php`: uma confirmação técnica legada não aparece como manual, uma confirmação histórica independente sem ator permanece positiva e `totalsLean()` equivale a `totalsAggregated()`. `WhatsappWebProbeV4Test.php` verifica a separação do índice técnico e do diretório manual. Nenhum dos testes originais de contagem foi alterado para contornar falhas.
- `WhatsappPublicContactsV14.php` não foi modificado nesta etapa; `WhatsappDossierProbeV91Test` segue aprovado e mantém `web_found_unofficial` como `unknown` no dossiê, preservando evidência e probe.
- Validação isolada dirigida: 37 aprovados, 245 assertions. `composer types:check`: aprovado. `composer ci:check`: Pint 832 arquivos aprovado, PHPStan sem erros, suíte completa com 1.448 aprovados, 2 ignorados, 0 reprovados e 7.604 assertions. Os relatórios `ACHADOS.md` e `VALIDACAO.md` detalham seletores e ambiente.
- Não houve staging, commit, push, exclusão, migration operacional ou alteração do banco real. As alterações preexistentes da árvore de trabalho foram preservadas.

| Etapa | Estado | Evidência / pendência |
|---|---|---|
| Preservar estado original | Concluída | Backup inicial e backups suplementares com hashes |
| Baseline crítica | Parcialmente concluída | Pest isolado e teste Node do classificador |
| Campanhas e seleção | Corrigido parcialmente | V91/V92/V93/V94 e runtime legado: 40 testes focados incluídos no lote, seleção exata, UUID por aba, redirect canônico, rascunho e transições; browser/concorrência PostgreSQL pendentes |
| WhatsApp e seis falhas do CI | Estabilizado nesta etapa; auditoria ainda parcial | 37/37 testes dirigidos e dossiê aprovados; `found` fica técnico no índice, status manual separado; CI completo verde (1.448 aprovados, 2 ignorados). Faltam PostgreSQL/concorrência e auditoria funcional integral |
| Limpeza localizada | Concluída para 69 marcadores e `nul` | Cópias externas verificadas; demais candidatos mantidos |
| Dashboard — catálogo de etapas | Funcional nos testes; análise estática bloqueada | A consolidação reduz de duas para uma consulta e os 5 testes seletivos passam (16 assertions). PHPStan ainda aponta `missingType.iterableValue`; a edição pontual do docblock foi recusada com erro 1224. Não usar CI completo até corrigir e repetir PHPStan. Backup pré-edição verificado. |
| Demais módulos e cobertura integral | Pendente | Empresas, HubSpot e Leads têm lotes parciais; status HubSpot agora segue paginação, mas os demais fluxos e os módulos Receita, Gestão, infraestrutura e frontend continuam sem revisão integral |
| Navegador e homologação visual | Pendente | Requer ambiente web isolado; nenhum serviço operacional foi iniciado |

## Próximos passos

1. Retomar a leitura integral de arquivos próprios por módulo, preservando o inventário atual de 1.764 registros; a estabilização do CI não equivale à conclusão da auditoria.
2. Completar rastreamento de rotas legadas, jobs/fila serializada, permissões, operação e integração do wizard.
3. Auditar Empresas, HubSpot, Leads, Receita, Gestão, infraestrutura, histórico Git, CSS/JS, Docker/ops e app HubSpot.
4. Executar homologação de navegador somente com dados sintéticos e integrações bloqueadas.
5. Examinar resíduos restantes e documentar recuperação e referências antes de qualquer retirada.
6. Executar suites/builds seguros pendentes; registrar resultados e limitações.

## Lote Dashboard — 2026-11-13 (incompleto)

- Leitura integral do mapa interativo (377 linhas); referências dinâmicas confirmadas em `map-panel-v30.blade.php` e `strategic.blade.php`. O SVG fornece foco/teclado nos estados e encaminha a seleção a `openExplorer`.
- Busca de imports confirmou que os CSS versionados antigos consultados continuam ativos e que testes protegem parte desses imports. Não há base para remoção só pelo sufixo de versão; nenhuma exclusão foi feita.
- Causa de desempenho confirmada por teste: 2 consultas ao catálogo `hubspot_pipeline_stages` em `forUser()`, ambas para construir dados do mesmo snapshot. A tentativa de fusão deslocou `$stageLabels` para o escopo privado e deixou referência indefinida em `forUser()`.
- Estado atual do código: `DashboardOwnedDealsV23Service.php` e o teste de contagem têm alterações locais não staged. A suíte `DashboardOwnedDealsSelectiveLoadV173Test.php` falha em 5/5 casos. O arquivo de serviço possui backup pré-edição verificado; tentativas de correção retornaram erro 1224 e não foi usado método alternativo. O processo responsável não foi identificado; evidências e limites estão em `VALIDACAO.md`.
- Próximo passo seguro: liberar o arquivo sem encerrar processos indiscriminadamente; corrigir a consolidação preservando rótulos com zero negócios e precedência do catálogo; reexecutar primeiro o teste novo e o arquivo seletivo, depois a suíte Dashboard; então Pint, PHPStan, CI e atualizar este estado. A auditoria dos outros módulos continua pendente; este lote não está concluído.

## Companies — lote parcial

- Foram examinados `CompanyService`, `CompanyGroupEnrichmentService`, modelo `Company`, contrato e provider local de dados Receita, migration de empresas e quatro testes associados.
- Uma reprodução mostrou persistência parcial quando uma filial posterior tinha raiz CNPJ inválida. Foi adicionada transação em torno da escrita do grupo e do cálculo ICP, mantendo a busca ao provider fora dela.
- Quatro suítes isoladas passaram após a correção (16 testes, 60 assertions); PHPStan direcionado e Pint nos dois arquivos alterados passaram. O código também mantém raiz CNPJ única, preserva campos em enriquecimento parcial, substitui CNAEs autoritativos e evita recarregar relações por estabelecimento.
- Sem remoções. O custo por CNAE ainda requer medição; o restante do módulo Companies não foi auditado.

## Leads — fila de tarefas (lote parcial)

- Foram examinados integralmente os serviços da fila V54 e exportação, o wrapper de métricas Dashboard, o modelo `HubSpotTask` e os testes V54/V12; da migration do espelho HubSpot, somente a tabela de tarefas e índices foram examinados.
- Cinco seletores dirigidos de Leads/Dashboard passaram (11 testes, 53 assertions), em SQLite isolado e sem chamadas HubSpot.
- Não houve alteração de código. Owner ID/fallback legado, estados de vencimento, autorização de exportação e neutralização de fórmulas estão cobertos. `is_open` e `due_at` têm índices, mas plano PostgreSQL e volume real não foram medidos.
- Pendência de qualidade: string de data inválida no export pode cair em “Sem prazo”; a ingestão precisa ser rastreada antes de definir correção. A revisão integral de Leads continua pendente.

## HubSpot — paginação do status de Leads (lote parcial)

- Foram examinados integralmente `HubSpotLeadStatusSyncService.php`, o teste desse serviço, `HubSpotHttpClient.php`, `HubSpotLeadStatusCandidateService.php`, seu teste, o comando e seu teste, além dos jobs manual/realtime/bulk relacionados. Em `routes/console.php`, somente a entrada agendada do comando foi inspecionada. O inventário foi atualizado com os hashes/tamanhos atuais; CSS `app.css` tem 440.788 bytes (a validação anterior registrava incorretamente 430.788, sem revisão integral do conteúdo).
- A consulta de associações ignorava `paging.next.after` depois de pedir até 100 registros. Isso podia omitir tarefas e calcular incorretamente `open_task_count`/status quando havia mais de uma página. A correção segue os cursores, deduplica IDs e detecta cursor repetido.
- O teste novo simulou 100 tarefas na primeira página e uma na segunda. Suíte do serviço: 7/7 testes, 18 assertions; candidato de sincronização: 2/2, 2 assertions; comando (sucesso e falha): 2/2, 5 assertions. Total deste recorte: 11 testes, 25 assertions. Pint nos dois arquivos alterados e PHPStan no serviço passaram. `Http::fake()` interceptou as chamadas; nenhum serviço externo foi consultado.
- O comando está agendado a cada cinco minutos, com `onOneServer()` e `withoutOverlapping()`. Nenhum scheduler, worker ou job foi iniciado; os jobs foram lidos, não executados. Seus testes de recuperação/concorrência continuam pendentes.
- A leitura individual de cada tarefa permanece como custo N+1 em HTTP. Batch-read e medição de rate limits são pendentes; não houve mudança sem prova de compatibilidade. Backup externo pré-edição verificado em `/home/adolfo/backups-prospector/auditoria-20261113-hubspot-status-batch/`, manifesto SHA-256 `72758113245c26f80ae599423da04cc282d3ccac9854965b8f44af0fba5d9871`.
- Não foi executado CI completo: a suíte seletiva Dashboard continua reprovada pela alteração incompleta/bloqueada já registrada. O lote HubSpot é parcial e não encerra a auditoria.

## HubSpot — entrada/retry de webhooks (fatia parcial)

- A rota `/webhooks/hubspot` usa middleware obrigatório de assinatura. A verificação HMAC v3, o limite temporal, unicidade de evento, reserva banco→fila, claim atômico, terminalidade e janelas de recuperação foram examinados em código.
- Quatro suites combinadas passaram: **13 testes, 50 assertions**, com filas fake e sem worker, scheduler, rede ou migration operacional.
- Nenhuma alteração foi necessária no fluxo de entrada/retry. O mirror/contexto e o command de reconciliação foram revisados em lote posterior (ver seção HubSpot de 2026-11-13); o resolver e os demais leitores/escritores, retenção/acesso aos payloads e a resposta 202 para lotes parcialmente inválidos continuam pendentes de análise do contrato.
- Uma nova tentativa de aplicar a correção do Dashboard retornou novamente erro Windows 1224 (seção mapeada); a causa/processo continua não identificado e `handle.exe` não está disponível. O arquivo não foi sobrescrito por método alternativo. Testes do serviço continuam 5/5 reprovados por `$stageLabels` indefinida; seguir apenas com módulos independentes até desbloqueio.

## HubSpot — resolver de associações (bloqueado)

- A leitura integral de `HubSpotWebhookAssociationResolver.php` confirmou que sua consulta v4 também para em `limit=100` e não segue `paging.next.after`; atividades/tasks podem perder vínculos após a primeira página. `HubSpotWebhookObjectTypeService.php` foi lido integralmente para confirmar os tipos/paths.
- O teste novo pretendia simular 100+1 associações, mas acabou aninhado no callback do teste existente; o runner executou apenas o teste anterior (1/1). A tentativa de reposicionar o caso no mesmo arquivo recebeu Windows 1224. Nenhum patch de produção foi feito e o teste não é evidência válida.
- Restart Manager identificou zero processos; `handle.exe` indisponível. Não foi usada escrita alternativa. Manter esse lote pendente e só editar/testar quando liberado; seguir agora com análises independentes.

## Companies — enriquecimento fiscal/CNAE (lote parcial)

- `CnpjEnrichmentService.php` e os testes de status cadastral/CNAE e enriquecimento BrasilAPI foram lidos integralmente. Seis testes passaram (24 assertions), todos com payload sintético e sem chamadas a provider ou fila.
- CNAEs são substituídos apenas com fotografia completa; payload ausente/inválido preserva os conhecidos. Não houve alteração de código.
- `metadata.qsa` persiste dados pessoais da fonte; busca estática em PHP/Blade/JS do código próprio não encontrou consumidor. Manter por ora e revisar finalidade/retention com o contexto de negócio. O job de enriquecimento foi examinado na continuação abaixo; páginas, uploads e restante do pipeline de importação seguem pendentes.

## Imports — job de enriquecimento e isolamento — 2026-11-13

- Lidos integralmente: `app/Jobs/EnrichImportItem.php`, `tests/Feature/Imports/EnrichmentQueueTest.php`, `app/Services/ImportQueueService.php`, `app/Services/ExportResearchQueueService.php`, `app/Services/CrmCheckService.php`, `phpunit.xml` e `tests/TestCase.php`. Em `HubSpotCrmCompanyProvider.php`, foram verificados somente `findCompany()` e `assertConfigured()` para confirmar a dependência externa; a classe completa permanece pendente.
- A leitura não mostrou regressão que justificasse mudar o job: erro temporário de provider retorna à fila e é repropagado para retry, not-found habilita fallback pontual, erro comercial fica registrado sem invalidar Receita e o refresh do lote ocorre no `finally`. Não houve alteração de produção ou remoção.
- A primeira execução passou (10 testes/52 assertions), mas não tinha bloqueio HTTP. O binding padrão do CRM aponta para HubSpot, e casos `dispatchSync` não substituíam esse provider em todos os cenários; não é possível determinar retrospectivamente se houve tentativa/leitura externa. A chamada analisada é de consulta e não há evidência de escrita. O resultado foi registrado como não isolado, sem alegar que não houve tráfego.
- Foi adicionada proteção permanente ao próprio teste: token HubSpot fictício, pesquisa externa desativada e `Http::preventStrayRequests()` antes de cada cenário. Reexecução com SQLite `:memory:`, token de processo vazio, proxy HTTP(S) local de bloqueio e providers de CNPJ sintéticos: **10 aprovados, 0 reprovados, 0 ignorados; 52 assertions**. O CRM é substituído por fake no cenário que verifica integração automática.
- Backup do teste antes da edição preservado em `/home/adolfo/backups-prospector/auditoria-20261113-import-test-isolation/`, manifesto `SHA256SUMS`. Inventário existente mantido: 1.764 registros de arquivo; os dois registros examinados foram atualizados sem reiniciar o inventário. A validação também corrigiu quatro notas com vírgulas sem escape; todas as linhas agora têm 11 colunas e não há caminhos duplicados.
- Próximo avanço: fechar o fluxo restante de Imports com os mesmos limites de isolamento; depois retomar os módulos independentes. Dashboard segue bloqueado por `$stageLabels` indefinida, e o teste/serviço de paginação de associações de webhook segue bloqueado pelo erro 1224. Não iniciar CI completo enquanto a suíte Dashboard estiver reprovada.

## Imports — lote de CNPJs e desempenho — 2026-11-13

- Causa medida: `CnpjImportService::import()` fazia um `SELECT` à tabela `establishments` para cada CNPJ válido inédito. A reprodução em SQLite com 40 CNPJs sintéticos falhou com **40 lookups**.
- Correção: a verificação de existentes é pré-carregada em `whereIn` por blocos de 500; a unicidade de `establishments.cnpj` foi confirmada na migration. Ordem e número original das linhas, normalização, contagem de duplicatas e vínculo ao `company_id` foram preservados.
- Resultado comparável: **40 → 1 SELECT** no mesmo lote de 40 (−97,5% nos lookups). Não representa latência/produção; no limite de 10.000 linhas, a implementação faz no máximo 20 consultas de lookup, sem benchmark máximo.
- O teste novo reproduz a contagem anterior e cobre a classificação existing/duplicate e o `company_id`. Suites de importação e comando de prospecção com HTTP fake/guardado: **25 testes e 109 assertions aprovados**. Pint e PHPStan direcionados passaram.
- Backups anteriores à edição do serviço/teste em `/home/adolfo/backups-prospector/auditoria-20261113-import-batching/`, manifesto `SHA256SUMS`. Nenhuma migration, fila operacional, provider real ou dado operacional foi usado.
- Continuação em 2026-11-13 — UI/contratos: lidos integralmente o componente Volt (762 linhas), a view Figma (324 linhas), o CSS consolidado e quatro testes; conferida a rota e o middleware comercial. `app.css` importa a folha consolidada uma vez (linha 18069). A visibilidade global dos lotes permanece sem decisão/isolamento entre usuários (A-30). `COUNT(*)` recorrente e busca com wildcard foram registrados como hipótese de custo a medir (A-31), sem otimização especulativa.
- Testes dirigidos de layout/workspace/contraste/redesign: **13 aprovados, 0 reprovados, 0 ignorados; 84 assertions**. Incluindo as suites de processamento/importação deste lote: **38 aprovados e 193 assertions**. Pint/PHPStan não foram repetidos para esta fatia visual; browser real, acessibilidade e perfil PostgreSQL seguem pendentes.
- Pendente dentro de Imports: resolver a atomicidade de `--replace` após a pré-validação; confirmar escopo do `batchId`; testar erros/retries da APIBrasil sem ativar o provider; obter medição de desempenho representativa e executar browser/E2E. A cobertura de jobs e commands permanece parcial. Nenhuma exclusão ou consolidação estrutural nesta etapa.

## HubSpot — sincronização do espelho e associações — 2026-11-13

- Foram examinados integralmente o serviço de espelho (1.903 linhas), os testes de mirror (1.121 linhas), o job de webhook, modelo de evento, contexto de referências e serviços de associação/vínculo fiscal; o command de reconciliação também foi lido. A migration foi consultada apenas para referências aos pivôs, sem execução.
- Três reproduções iniciais falharam conforme esperado: 100 de 101 vínculos sincronizados; resposta de associações sem `results` removia vínculo conhecido sem erro; falha HTTP 503 do catálogo era tratada como sucesso com `stage_id` no lugar do rótulo.
- O espelho agora pagina associações com cursor, deduplica, interrompe explicitamente em cursor repetido/estrutura inválida e propaga falhas do catálogo de pipelines. Testes também confirmam que a associação local permanece após resposta estrutural inválida e que falha do pipeline não cria negócio no mirror.
- Testes combinados de mirror, placeholders e webhook: **22 aprovados, 0 reprovados, 0 ignorados; 88 assertions**. Pint, PHPStan direcionado e `git diff --check` passaram. HTTP foi fake; banco SQLite `:memory:`; nenhuma chamada à API ou escrita operacional.
- Backup dos dois arquivos anteriores à edição verificado em `/home/adolfo/backups-prospector/auditoria-20261113-webhook-mirror-pagination/` (SHA-256 em `SHA256SUMS`).
- Um refinamento para tratar 404 depois da primeira página e rejeitar entradas individuais malformadas não foi aplicado após erro Windows 1224. Restart Manager listou PIDs de processos Git durante as consultas; eles já não estavam disponíveis na checagem seguinte. `fuser`/`lsof` não mostraram processo WSL. Causa não identificada; não houve encerramento nem sobrescrita alternativa. Esses dois casos permanecem pendentes, assim como o resolver independente em A-27.
- Próximo lote: retomar o resolver de associações apenas quando o arquivo de teste estiver liberado; seguir em paralelo pela sincronização de atividades e comandos de recuperação. Não marcar auditoria HubSpot ou integral como concluída.

## HubSpot — Primary Company em associações — 2026-11-13

- Lidos integralmente o serviço de sincronização de associações, seu teste e o command `hubspot:sync-deal-primary-companies`; o command não tem teste direto localizado e não foi executado.
- Corrigida a integridade da fotografia: respostas malformadas, IDs ausentes/inválidos, cursores repetidos e 404 após a primeira página agora falham explicitamente antes de substituir os pivôs. O 404 inicial continua representando ausência de associações.
- As três regressões adicionadas falharam antes da correção; depois, o teste Primary Company passou com **6 testes/19 assertions**. Suíte combinada HubSpot: **28 testes aprovados, 0 reprovados, 0 ignorados; 107 assertions**. Pint, PHPStan das classes de produção e `git diff --check` passaram.
- PHPStan no arquivo de teste ainda acusa duas asserções nullsafe preexistentes (linhas 349/357), não relacionadas e sem alteração. Backup pré-edição verificado em `/home/adolfo/backups-prospector/auditoria-20261113-hubspot-primary-cursor/`.
- O inventário existente foi atualizado para o serviço e teste. Permanecem pendentes: o resolver do webhook (A-27), o refinamento bloqueado do serviço de mirror (404 posterior e entradas individuais malformadas), testes diretos do command e a falha seletiva de Dashboard que impede declarar o CI completo verde.
- Próximo lote: prosseguir pelo resolver de associações e fluxos de atividade/reconciliação HubSpot, mantendo HTTP fake e sem iniciar commands operacionais. Não retomar limpeza geral nem declarar auditoria integral concluída.

## HubSpot — atomicidade do status e da auditoria — 2026-11-13

- Corrigida a inconsistência entre a atualização do status comercial e seu evento de auditoria: o snapshot anterior é agora lido sob `lockForUpdate()`, e a atividade é persistida dentro da mesma transação do status.
- Teste de regressão provoca falha SQLite na gravação da atividade e verifica que o status é revertido para `new`; a exceção não é ocultada.
- Suíte combinada isolada de status, candidatos, command, retry HTTP e timeline: **20 testes aprovados, 0 reprovados, 0 ignorados; 53 assertions**. Pint e PHPStan direcionados passaram; `git diff --check` passou.
- Os arquivos alterados foram preservados no backup externo pré-edição `auditoria-20261113-hubspot-status-audit`. Hashes finais e estado de leitura registrados no inventário existente, sem recriar o inventário.
- Não houve chamada real à API, execução de worker/scheduler, alteração de banco operacional, staging, commit ou push. O custo de um GET individual por tarefa continua sem medição real; batch-read permanece pendente.
- A auditoria integral continua parcial. Priorizar a revisão dos fluxos HubSpot restantes e módulos independentes; não retomar a limpeza geral sem evidência de dependência e recuperação. Não repetir o CI completo enquanto a suíte Dashboard seletiva permanecer bloqueada/reprovada.

## Imports/HubSpot — proteção da substituição e providers — 2026-11-13

- Revisão integral dos providers CNPJ, do serviço de enriquecimento, do command e do importador CSV HubSpot (2.369 linhas), além dos testes associados. `CnpjDataProvider` está vinculado a `BrasilApiCnpjProvider`; `ApiBrasilCnpjProvider` tem configuração e teste direto, mas não possui call site de produção nem binding encontrado. Mantido até confirmar se é fallback planejado.
- Causa A-32: `--replace` limpava tabelas `hubspot_*` e execuções históricas antes de validar caminhos/ZIP/cabeçalhos. Corrigido para resolver os quatro arquivos e validar os cabeçalhos obrigatórios antes da limpeza; falha nessa pré-validação preserva o espelho anterior e grava execução `failed`. O teste do snapshot agora cobre sucesso de substituição; teste adicional cobre caminho ausente sem apagar o registro antigo.
- Suíte combinada após a alteração (providers e importação HubSpot): **6 aprovados, 0 reprovados, 0 ignorados; 30 assertions**. Pint (2 arquivos), PHPStan direcionado ao serviço e `git diff --check` passaram.
- Backup dos arquivos de código/teste antes da edição em `/home/adolfo/backups-prospector/auditoria-20261113-hubspot-import-preflight/`, com `SHA256SUMS` verificado.
- Limite ainda não resolvido: escrita após pré-validação não é transacional; falha de banco durante `--replace` pode produzir substituição parcial. Não executar esse modo em dados operacionais antes de projetar/testar rollback em PostgreSQL. O custo de memória das associações e transação longa não foi medido com export representativo.
- Tentativa de ampliar a correção para atomicidade foi bloqueada no arquivo pelo erro Windows 1224. O SHA-256 permaneceu igual ao arquivo validado; nenhum mecanismo alternativo de escrita ou encerramento de processo foi usado. Segui com a investigação independente dos skips do CI.
- Próximo lote: concluir o contrato de atomicidade do importador antes de operação real; revisar comportamento de erro/retry da APIBrasil sem ativar o provider; seguir para os demais fluxos HubSpot e módulos independentes. Continuação da auditoria geral ainda parcial.

## Motivo dos dois testes ignorados — confirmado — 2026-11-13

- `RegistrationTest.php` contém exatamente dois casos condicionados por `Features::registration()`. A feature não consta em `config/fortify.php`; `tests/TestCase.php` os marca skip com a mensagem explícita. Execução isolada reproduziu os **2 ignorados e 0 assertions**. A política de registro aberto desativado está comentada na configuração; cobertura de convites não foi verificada neste lote.
- Não alterei flags Fortify nem converti skips em aprovação. O CI completo não foi repetido nesta continuação, pois os bloqueios/falhas seletivas do Dashboard permanecem documentados.

## HubSpot — integridade do mirror de atividades — 2026-11-13

- Baseline de serviço: 3 testes/15 assertions passaram. A reprodução de resposta HTTP 200 malformada falhou antes do fix porque `properties` não era validado e podia sobrescrever a atividade local com conteúdo vazio.
- `HubSpotActivitySyncService::readActivity()` agora rejeita resposta sem `properties` array antes de alterar mirror/projeção. O teste comprova preservação do conteúdo válido já armazenado.
- Três suítes isoladas de atividades passaram: **9 testes, 47 assertions**; Pint, PHPStan do serviço e `git diff --check` passaram. Backup pré-edição verificado em `/home/adolfo/backups-prospector/auditoria-20261113-hubspot-activity-validation/`.
- Após as correções de Primary Company e mirror de atividades, a suíte combinada HubSpot de associação/webhook/atividade passou com **37 testes, 154 assertions**, sem falhas ou skips; Pint e PHPStan dos serviços também passaram.
- A leitura dos chamadores foi parcial; o command `hubspot:rebuild-activity-projections` não foi executado. Seguem pendentes sua revisão integral, contrato de retry, retenção de `raw_properties`, resolver de associações A-27, revisão restante do HubSpot e CI completo enquanto Dashboard falhar.

## HubSpot — vínculo fiscal / promoção de atividade — 2026-11-13

- Revisados integralmente serviço de vínculo manual, teste, modelo HubSpot Company e migration do espelho/pivô de atividades. O fluxo valida conflitos e persiste o vínculo, CRM, projeção comercial e estado de trabalho em uma transação.
- Testes direcionados: **3 aprovados, 23 assertions**; PHPStan do serviço sem erros. A Receita local foi simulada por `Http::fake()`. Nenhuma operação externa/real.
- Nenhuma correção foi necessária. Falta um teste integrado que vincule uma Company HubSpot e promova uma atividade no mesmo cenário; custo de `ownerId()` e duração da transação seguem sem medição.
- Revisado integralmente `RebuildHubSpotActivityProjections.php` sem execução: A-37 registra que o command apaga todas as projeções antes de reconstruir em lotes, sem teste próprio; erro no meio deixa resultado vazio/parcial. `--dry-run` não valida escrita. Não executei nem alterei dados.
- Próximo foco: definir e testar forma segura de publicar a reconstrução antes de uso operacional; depois continuar atividades/reconciliação HubSpot. A-27 e a falha seletiva de Dashboard seguem pendentes.

## Dashboard — catálogo de etapas após correção parcial — 2026-11-13

- Reproduzida a regressão antes da correção: 5 testes falharam por `$stageLabels` indefinida, zero assertions.
- O serviço agora carrega mapa e lista de rótulos no mesmo snapshot do catálogo, compartilha esse resultado com `forUser()` e mantém a compatibilidade das listas de etapas sem negócios. O teste seletivo passou: **5 testes, 16 assertions**; o teste de contagem mede uma consulta ao invés de duas.
- Pint e `git diff --check` passaram. PHPStan ainda falha com `missingType.iterableValue` no tipo genérico do parâmetro opcional; a tentativa de adicionar o docblock encontrou erro 1224. Não usei escrita alternativa nem encerrei processos; WSL `lsof`/`fuser` não acharam holder.
- Snapshot pré-edição verificado em `/home/adolfo/backups-prospector/auditoria-20261113-dashboard-stage-catalog/`. Não executar CI completo até eliminar o erro PHPStan e repetir a validação. O restante do Dashboard e a auditoria integral continuam pendentes.

## HubSpot — resolução de contatos — 2026-11-13

- Corrigida inconsistência de idempotência: resposta de busca sem `results`/ID ou resposta de contato sem `properties` array não é mais interpretada como ausência/canais vazios. Erros agora interrompem antes de criar contato duplicado ou enviar PATCH.
- Reproduções pré-fix falharam nos dois cenários; cobertura final inclui também item de busca sem ID e mantém a regra legítima de lista vazia. Testes resolver/candidatos/envio: **8/8, 43 assertions**. Escrita de associações sem retry em HTTP 500: **3/3, 6 assertions**.
- Pint, PHPStan do resolver e serviço de associação passaram; `git diff --check` passou. Backups externos pré-edição verificados. HTTP fake e banco `:memory:`, sem chamadas HubSpot reais.
- Pendências de privacidade/integração: revisar conteúdo de bodies de erro; buscar regressões de telefone quando a formatação difere; medir limites. Também seguem A-27, A-37 e o erro PHPStan do Dashboard. Não executar CI completo antes de resolver esse erro.
- Execução final combinada das suítes HubSpot abrangidas nesta etapa: **48 testes aprovados, 203 assertions, zero falhas/ignorados**; Pint em 10 arquivos e PHPStan em cinco serviços passaram. O seletivo Dashboard permanece fora desse grupo e PHPStan ainda retorna um erro.
