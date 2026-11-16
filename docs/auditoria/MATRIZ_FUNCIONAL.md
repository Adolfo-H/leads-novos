# Matriz funcional

Matriz parcial baseada nos fluxos e testes efetivamente revisados. “Pendente” significa que não houve homologação completa no navegador/ambiente isolado.

| Fluxo | Entrada e autorização | Comportamento esperado | Evidência disponível | Pendente |
|---|---|---|---|---|
| Nova campanha / URLs antigas | Rotas de campanhas; middleware e autorização do usuário | Entradas visuais, modelos, edição e transferência V91/V92 convergem ao wizard V93, sem loop; sessão segregada por UUID | GET legado seguido até página final; suíte de consolidação, modelos e duas abas aprovada | Browser, redirects em proxy e navegação real |
| Filtros de campanhas | Índice de campanhas; usuário autorizado | Todas, rascunhos, enviando, pausadas, concluídas e canceladas filtram e preservam pesquisa | Testes de consolidação/UI existentes | Browser e estados reais sintéticos |
| Busca e filtros de contatos | Página de contatos; autorização do módulo | Busca por nome/empresa/email e filtros de origem antes da paginação | Testes dirigidos de contatos | Browser, consulta e paginação em banco representativo |
| Seleção de contatos | Checkboxes e estado da sessão associado a token/UUID | Seleção persiste em páginas/filtros; remover destinatário preserva busca/origem/página; abas isoladas | V91/V92/V93 e teste de duas abas; teste de remoção com filtros passou; e-mail revalidado no POST e ao salvar | Browser e tamanho/desempenho em grande volume |
| Contatos → criar campanha | POST autenticado com token, seleção e snapshots | Transferir somente identidades e e-mails atuais válidos; exibir erro sem perda silenciosa | Testes do POST V92 aprovados | Browser, mensagens visuais e limite real |
| Salvar/editar rascunho | Ações autenticadas e CSRF | Salva/atualiza a mesma campanha sem envio ou duplicação | Testes de wizard/consolidação | Reenvio concorrente e browser |
| Aprovar, pausar, retomar, cancelar | POST autenticado, confirmação e estado atual | Transições válidas sob lock; GET nunca efetua ação; repetição no estado-alvo idempotente | Testes SQLite isolados de segurança/consolidação | PostgreSQL, concorrência e UI real |
| Envio de campanha | Serviço de campanha, supressões e fila | Nenhum envio real nos testes; tentativas incertas não são reenviadas automaticamente | `CampaignAudience` consultado em lote; mailers explicitamente falsos/desativados | Teste de fluxo completo isolado, SMTP não executado |
| Verificação WhatsApp manual | Formulário de contato/dossiê, autorização e CSRF | Confirmação explícita e observação real para ausência; rejeição não grava estado/evento; sync vinculado respeita trilha | Testes V941 e cross-screen aprovados | Browser e propagação com conjunto PostgreSQL representativo |
| Consulta técnica WhatsApp | Probe/agent e biblioteca Baileys instalada | `found` é positivo técnico no índice, sem confirmação manual; `not_found`/`inconclusive`/`error` não comprovam ausência | Suítes dirigidas 37/37 e CI completo aprovados; `WhatsappDossierProbeV91Test` preserva `unknown` no dossiê e a evidência `web_found_unofficial` | PostgreSQL/concorrência e auditoria histórica permanecem pendentes; consultas reais seguem desativadas |
| Busca externa / Receita / HubSpot | Serviços, rate limits e autorização de escrita | Dashboard não executa escrita remota; vínculo fiscal e identidade remota permanecem confiáveis | Não coberto integralmente nesta etapa | Revisão de contratos, filas, callbacks e testes isolados |

Nenhuma varredura WhatsApp, escrita HubSpot, envio de e-mail ou consulta operacional foi iniciada.
