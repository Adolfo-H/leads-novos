# Evolução futura priorizada

| Prioridade | Risco / impacto | Esforço | Dependências | Próxima ação |
|---|---|---|---|---|
| P0 | Alto: campanha pode duplicar, enviar indevidamente ou alterar estado incorretamente | Médio | PostgreSQL de teste, mail/HTTP/fila falsos | Homologar wizard, edição, idempotência e transições sob concorrência |
| P0 | Alto: identidade/e-mail da seleção pode mudar antes da transferência | Médio | Fixtures sintéticas e browser isolado | Executar E2E de seleção multi-página, token expirado e endereço alterado |
| P0 | Alto: histórico WhatsApp pode divergir entre índice técnico e confirmação manual | Médio | Dados sintéticos; PostgreSQL isolado ainda pendente | Contrato técnico/manual agora cobre `found`, respostas inconclusivas e proteção contra decisão humana; executar concorrência e revisar histórico sem varredura real |
| P1 | Alto: autorização e exposição de dados em módulos fora do escopo atual | Alto | Mapa completo de rotas e papéis | Auditar políticas, uploads/exports, recuperação e permissões por registro |
| P1 | Alto: regressão visual e scripts duplicados | Médio | Build Vite e navegador com dados sintéticos | Mapear imports CSS/JS e validar console, rede, responsividade e acessibilidade |
| P1 | Médio/alto: consultas caras, N+1 e cache entre usuários | Médio | PostgreSQL de teste e dataset sintético | Medir filtros/paginação e avaliar índices sem `EXPLAIN ANALYZE` operacional |
| P1 | Alto: falhas de reconciliação ou duplicação HubSpot | Alto | Fakes HTTP e contratos oficiais | Revisar assinatura, rate limits, retries, filas e resultado remoto incerto |
| P2 | Médio: referências históricas e artefatos confundidos com código ativo | Médio | Busca de referências e recuperação comprovada | Classificar ZIPs, instaladores e backups; manter enquanto houver dúvida |
| P2 | Médio: baseline ambiental frágil | Baixo | Configuração isolada de testes | Corrigir caminho do cache Blade sem limpar `storage` operacional |

Não iniciar envios, workers, scheduler, varreduras WhatsApp, migrations ou operações remotas como parte dessas tarefas sem autorização operacional separada.
