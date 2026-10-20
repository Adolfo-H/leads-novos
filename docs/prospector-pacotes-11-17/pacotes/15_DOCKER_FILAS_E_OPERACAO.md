# PACOTE 15 — Docker, filas, atualização segura e agendamentos

## Arquivos atuais

- `compose.yaml`: Laravel Sail, Postgres, Redis, Mailpit, `receita-data`, workers de importação/HubSpot/campanhas, scheduler e túnel/ngrok. Vários workers dependem apenas da ordem de start, não de prontidão real de PostgreSQL/Redis/Receita.
- `start.sh`: startup rotineiro usa `--no-recreate` para preservar jobs; não deve ser tratado como deploy de nova versão. Atualmente inicializa Laravel/workers e pode deixar `receita-data` de fora se não estiver rodando.
- `composer.json`: `composer setup` roda `artisan key:generate`; **não usar esse script para atualização de ambiente já existente**.
- `docker/campaign-unsubscribe/nginx.conf`: gateway de descadastro precisa de revisão com rota real do HubSpot webhook.
- `routes/console.php`, `config/queue.php`, `config/database.php`, `app/Jobs` e `app/Console/Commands`.

## Implementações

1. Criar dois fluxos independentes e **não confundir**:
   - `start.sh` ou `tools/ops/start-local.sh`: abrir a instalação sem recriar volumes, sem limpar Redis locks, sem alterar APP_KEY ou migrations; verificar se Laravel, PostgreSQL, Redis e Receita estão saudáveis; mostrar resumo confiável de serviços.
   - `tools/ops/update-safe.sh` (novo): plano de atualização controlada de código/config com opção `--dry-run` por padrão; check backup; diff composer/npm; migrations `--pretend`/análise; somente depois autorização para update e reciclagem graciosa de workers. Nunca derrubar worker que esteja concluindo SMTP incerto sem diagnóstico.
2. Compose: healthchecks reais para PG/Redis/Receita; `depends_on: condition: service_healthy` onde cabível, mantendo compatibilidade com perfis `background`; `receita-data` deve iniciar quando necessário para o app. Cuidado com contêiner que não possui binários de healthcheck. Validar `docker compose config` em ambiente real.
3. Queue/Redis: verificar `retry_after` > maior `--timeout` (incluindo filas especializadas), retentativas, backoff e `failed_jobs`; evitar jobs duplicados após reboots; não limpar fila sem triagem. Separar fila HubSpot realtime, bulk, webhooks e campanhas para evitar starvation. Não aumentar workers da campanha sem revisar cota global.
4. Scheduler: garantir execução em apenas um nó ou locking via Redis conforme arquitetado; prevenir overlapping com `withoutOverlapping`/locks e recuperação de falhas; health de agendamento e workers com aviso quando não houver execução recente.
5. Webhooks: checar exposição HTTPS do endpoint HubSpot pelo gateway ngrok e assinatura obrigatória, sem expor endpoints internos nem misturar descadastro e webhooks. Não trocar `APP_URL` de desenvolvimento por URL externa indiscriminadamente.
6. Script de update nunca faz `php artisan key:generate`, `docker compose down -v`, `migrate:fresh`, `migrate:refresh`, `queue:clear`, `cache:clear` geral, nem ignora erros com `|| true` no componente crítico.
7. Logs e diagnósticos fora da raiz; rotação de logs, métricas de worker/queue, verificação de espaço livre dos arquivos Receita; procedimento testado de backup + restore do Postgres em homologação.

## Validação

- `bash -n start.sh` e `bash -n tools/ops/*.sh`.
- `docker compose config --quiet` com ambiente existente (sem publicar segredos).
- `tests/Feature/Infrastructure/` e novos testes de startup/deploy em modo fake.
- Simular parada do Redis ou Receita: mensagens devem identificar serviço indisponível; script não deve declarar OK.
- Verificar comportamento da fila com jobs pendentes e worker da versão antiga; update recarrega worker graciosamente, sem reprocessamento incorreto.
- Documentar comandos de arranque, atualização, diagnóstico, rollback e procedimentos de migração.

## Aceite

Nenhum deploy, restart de containers, execução real de migration, alteração de porta exposta ou segredo como consequência automática deste pacote. Tudo testável e documentado; ativação somente sob autorização.
