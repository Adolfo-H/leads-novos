# Relatório final — Pacotes 11–17

## Status executivo

| Etapa | Resultado | Evidência/limite |
|---|---|---|
| Pacotes 11–15 | **PASS** | Implementações e validações registradas nos relatórios por pacote. |
| Pacote 16 | **PASS — técnico automatizado** | Build, auditorias, CI PHP, testes Python e Compose aprovados. |
| Pacote 17 | **PASS — testes automatizados; BLOQUEADO — homologação operacional/visual** | Sem alvo isolado de homologação autorizado; serviço Receita local parado; sem testes reais de navegador, performance ou restore de backup. |
| Homologação completa ponta a ponta | **BLOQUEADA** | Não declarar homologação integral enquanto os itens acima não forem executados em ambiente isolado aprovado. |

## Funcionalidades validadas por automação

- **Empresas e estabelecimentos:** listagem, serviços, enriquecimento, vínculos
  fiscais e integridade de CNPJ, incluindo identificadores alfanuméricos.
- **Receita Federal:** publicação/validação de release por fixtures, consulta
  local de CNPJ e grupo, manifesto, checksum, consulta paginada e proteção
  contra troca de dataset durante paginação.
- **ICP e SDR:** cálculo/versionamento, prévia e recálculo controlado em testes,
  proteção de clientes/oportunidades e invariantes de score.
- **Contatos:** filtros, paginação em duas fases, desempenho estrutural,
  seleção, vínculos e controles de acesso.
- **CRM e negócios:** verificação de elegibilidade, proteção de clientes e
  oportunidades existentes, autorização de vendedor e recuperação de
  resultado HubSpot incerto.
- **HubSpot:** requests simulados, associação/projeção local, retries,
  webhooks, assinatura, deduplicação e auditoria. Nenhuma requisição de escrita
  real foi feita.
- **Campanhas:** elegibilidade, sequências, respostas, bounce/DSN, cotas,
  reservas, filas, simulação e worker. Os testes usam fixtures/fakes; nenhum
  e-mail foi enviado.
- **Filas e infraestrutura:** configuração, heartbeats, saúde e scripts
  operacionais cobertos pelos testes; Compose validado sem recriar containers.
- **Login e permissões:** testes de autenticação, roles, acesso negado e
  autorização para ações HubSpot.
- **Build e segurança de dependências:** build frontend aprovado; npm e Composer
  audit sem vulnerabilidades/advisories.

Esses resultados comprovam as regras cobertas pela suíte em ambiente de teste,
não a execução real de todas as integrações ou telas em produção.

## Correções e mudanças realizadas

- **Pacote 11 — ICP:** avaliação de score separada da persistência, auditoria
  agregada e comando de recálculo dry-run/limitado com aprovação explícita.
- **Pacote 12 — HubSpot:** estado local para tentativas de negócio, bloqueio de
  duplicação após resposta remota incerta, auditoria e testes de webhook.
- **Pacote 13 — campanhas:** reconciliação histórica de destinatários, guardas
  para follow-up, controle transacional de cota e tratamento isolado de parsing
  IMAP/DSN.
- **Pacote 14 — Receita e prospecção:** releases validadas e imutáveis,
  manifesto/checksum, estatísticas pré-agregadas, paginação versionada e
  limites de processamento.
- **Pacote 15 — Docker e operação:** healthchecks, dependências saudáveis,
  logs limitados, scheduler/heartbeats e script de atualização seguro em
  dry-run por padrão.
- **Pacote 16 — segurança e dependências:** assinatura obrigatória de webhook,
  reforço de autorização, remoção de defaults não-placeholder e atualização
  compatível das dependências vulneráveis.
- **Pacote 17 — homologação:** execução da suíte completa e registro de
  resultados/pendências neste relatório. Não foi necessário criar testes
  adicionais: as suítes existentes cobriram os cenários automatizados listados.

Relatórios detalhados: [Pacote 11](./RELATORIO_PACOTE_11.md),
[Pacote 12](./RELATORIO_PACOTE_12.md),
[Pacote 13](./RELATORIO_PACOTE_13.md),
[Pacote 14](./RELATORIO_PACOTE_14.md),
[Pacote 15](./RELATORIO_PACOTE_15.md) e
[Pacote 16](./RELATORIO_PACOTE_16.md).

## Testes executados nesta homologação

- `npm run build`: **PASS**. 22 módulos transformados. Avisos não bloqueantes:
  fallback otimizado de fontes depende do pacote opcional `fontaine`; três
  URLs de assets públicos permanecem para resolução em runtime. Os arquivos
  referenciados estão presentes no repositório.
- `npm audit`: **PASS**, 0 vulnerabilidades.
- `composer audit`: **PASS**, sem advisories.
- `composer ci:check`: **PASS** — Pint: **542 arquivos**; PHPStan: **sem erros**;
  Pest: **906 passaram, 2 ignorados, 3.978 assertions**, em 49,70 s.
- `tools/email/test_imap_fetch.py`: **PASS**, 3 testes.
- `tests/python/test_campaign_dsn.py`: **PASS**, 8 testes.
- `tools/receita/test_release_manifest.py`: **PASS**, 4 testes, usando o
  container/imagem dedicado à Receita e somente fixtures temporárias.
- `docker compose config --quiet`: **PASS**.
- `git diff --check`: **PASS**.
- As suítes Pest usam `APP_ENV=testing`, SQLite `:memory:`, cache/queue/session
  isolados e mailer `array`, conforme `phpunit.xml`.

Uma tentativa inicial de executar o teste Receita dentro do container Laravel
não encontrou `duckdb`; a suíte foi repetida com sucesso no container Receita
dedicado, sem instalar dependências nem tocar dados do dataset. O teste IMAP
foi executado com `python3`, pois o alias `python` não existe no container
Laravel.

## Problemas encontrados

1. **Serviço Receita no Compose local:** `receita-data` estava `Exited (255)`
   há seis dias na inspeção. Não foi iniciado/reiniciado por não haver alvo
   isolado autorizado; a suíte de fixtures passou na imagem dedicada.
2. **Homologação visual e desempenho:** não executada em navegador desktop/mobile
   nem medida mediana/p95 contra PostgreSQL com dados de benchmark. Não há
   ambiente isolado autorizado com usuários/dados para essa etapa.
3. **Restore:** o procedimento de backup/restore está documentado, mas a
   restauração de backup não foi testada em ambiente isolado.
4. **Testes ignorados:** o CI informou dois testes como skipped; a saída
   resumida não incluiu os nomes/motivos.

Os avisos do build de assets/fonts não fizeram o comando falhar e não indicaram
arquivos ausentes no repositório.

## Migrations, flags e efeitos operacionais

- Migrations aditivas preparadas nos pacotes anteriores permanecem **não
  aplicadas operacionalmente**. Nos testes, o schema foi exercitado apenas pelo
  banco de teste isolado.
- Nenhuma `APP_KEY` foi regenerada; nenhum volume/banco foi apagado/resetado.
- Nenhum recálculo real de ICP/SDR, download nacional ou ativação de competência
  Receita foi executado.
- Não foram feitas sincronizações reais, POSTs ou negócios no HubSpot.
- Não houve envio SMTP, ativação de campanha, alteração de cota ou replay de
  destinatário incerto.
- Nenhum container ativo foi recriado/reiniciado; `start.sh`, modo apply do
  script de atualização e deploy não foram executados.
- Nenhuma mudança de produção foi feita.

## Aprovação humana necessária

- [ ] Aprovar migration PostgreSQL operacional após backup validado.
- [ ] Aprovar recálculo de scores reais (ICP/SDR) com escopo e amostra.
- [ ] Aprovar sincronização HubSpot real após conciliar estados incertos.
- [ ] Aprovar publicação/troca de competência Receita após validar a release.
- [ ] Aprovar liberação SMTP comercial/campanha piloto.
- [ ] Aprovar deploy e qualquer reinício/recriação de containers.
- [ ] Disponibilizar alvo isolado para restore, testes visuais desktop/mobile e
      benchmark mediano/p95.

## Sequência segura sugerida para homologação operacional

1. Preparar ambiente isolado identificado, sem credenciais/rotas de produção,
   com backup externo e plano de restauração.
2. Validar a imagem/healthcheck Receita nesse ambiente e executar restore ali,
   nunca sobre o volume ativo.
3. Revisar as migrations e aplicar somente no banco descartável de homologação;
   confirmar rollback/restauração antes de qualquer promoção.
4. Rodar a suíte completa e os fluxos de navegação/performance com dados
   sintéticos ou anonimizados; registrar mediana e p95.
5. Fazer revisão humana de código, migrations e scripts operacionais; aprovar
   separadamente qualquer integração, SMTP ou deploy.
6. Em eventual deploy aprovado, manter sincronizações e campanhas desligadas
   até aprovação explícita; observar healthchecks/filas e aplicar rollback por
   componente se necessário.

## Reversão

- Alterações de aplicação/dependências: reverter por commit/release controlada,
  sem resetar o worktree ou substituir mudanças independentes.
- Migrations: reversão somente após revisão de impacto e aprovação; preferir
  restauração do backup isolado se houver mudança incompatível de dados.
- Receita: preservar releases imutáveis; voltar o ponteiro ativo somente após
  validar a release anterior no ambiente isolado.
- Containers/filas: usar o procedimento documentado em
  [`tools/ops/README.md`](../../tools/ops/README.md), com backup, workers
  drenados e aprovação; não executar atualização apply automaticamente.

Nenhum commit ou push foi criado.
