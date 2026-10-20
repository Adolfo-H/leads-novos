# Operação local e atualização segura

## Inicialização de rotina

`bash start.sh` sobe Laravel e suas dependências com `--no-recreate`, inicia
workers e scheduler de background e imprime o estado de saúde dos serviços.
`receita-data` é opcional para o boot do Laravel: se não houver release ativa,
seu healthcheck pode permanecer indisponível sem derrubar a aplicação.

A sincronização inicial do HubSpot fica desativada por padrão. Só é iniciada
quando `HUBSPOT_STARTUP_SYNC_ENABLED=true` estiver configurado explicitamente
e `HUBSPOT_STARTUP_SYNC_LIMIT` for um valor entre 1 e 100.
O startup não executa `hubspot:health-ping`; o scheduler envia os heartbeats.
Como workers podem consumir jobs pendentes, execute `start.sh` apenas no
ambiente local autorizado.

Após o primeiro ciclo do scheduler, consulte saúde e contagens agregadas:

```bash
docker compose exec -T laravel.test php artisan ops:health
```

O comando mostra heartbeats recentes do scheduler e dos workers padrão e
HubSpot prioritários (e da fila de campanhas quando habilitada), além da
quantidade de jobs pendentes nas filas Redis, sem inspecionar o conteúdo das
mensagens. A ausência de heartbeat recente produz saída de erro; após um boot,
aguarde até três minutos pelo primeiro ciclo.

Os logs de contêineres usam `json-file` com limite de 10 MB e cinco arquivos.
Laravel usa o canal `daily` por padrão quando `LOG_CHANNEL` não está definido;
instalações com configuração explícita devem definir `LOG_CHANNEL=daily` para
ter retenção por `LOG_DAILY_DAYS` (14 dias no `.env.example`). Os logs do Vite
e da conferência inicial do HubSpot são rotacionados até cinco arquivos de
10 MB antes de uma nova execução.

## Backup e restore em homologação

Nunca grave dumps no repositório. Gere o backup em armazenamento seguro fora
dele; os comandos abaixo não são executados automaticamente por `start.sh` nem
por `update-safe.sh`:

```bash
BACKUP_FILE=/caminho/seguro/fora-do-repositorio/prospector.dump
docker compose exec -T pgsql sh -lc \
  'pg_dump -Fc -U "$POSTGRES_USER" "$POSTGRES_DB"' > "$BACKUP_FILE"
test -s "$BACKUP_FILE"
```

Teste o restore somente em um cluster/banco descartável de homologação, nunca
sobre o banco ativo:

```bash
RESTORE_DB=prospector_restore_check
docker compose exec -T -e RESTORE_DB="$RESTORE_DB" pgsql sh -lc \
  'createdb -U "$POSTGRES_USER" "$RESTORE_DB"'
docker compose exec -T -e RESTORE_DB="$RESTORE_DB" pgsql sh -lc \
  'pg_restore -U "$POSTGRES_USER" -d "$RESTORE_DB" --no-owner --no-privileges' \
  < "$BACKUP_FILE"
docker compose exec -T -e RESTORE_DB="$RESTORE_DB" pgsql sh -lc \
  'psql -U "$POSTGRES_USER" -d "$RESTORE_DB" -Atc "select count(*) from migrations"'
```

Valide tabelas essenciais e amostras sem dados pessoais antes de considerar o
restore aprovado. `update-safe.sh` verifica apenas se o arquivo informado é
legível e não vazio; isso não substitui um restore testado.

## Atualização controlada

O modo padrão é somente leitura:

```bash
bash tools/ops/update-safe.sh
```

O dry-run valida Compose e whitespace, lista alterações de dependências e
migrations, mostra serviços em execução e espaço livre no host e, se
`receita-data` estiver ativo, no volume de dados. Não instala pacotes, não
executa migrations e não reinicia containers.

Para aplicar uma versão versionada, primeiro faça backup, revise migrations e
confirme que não há jobs SMTP incertos. Faça drain gracioso dos workers e
scheduler, aguarde os jobs em execução concluírem e pare-os sem limpar filas.
O script recusa atualização enquanto qualquer worker/scheduler estiver ativo,
exige worktree limpo e backup legível fora do repositório, entra em manutenção,
instala dependências com lockfile e reinicia somente Laravel. Se uma etapa
falhar, tenta retirar Laravel do modo de manutenção e reporta falha.

Exemplos de autorização explícita (não executados automaticamente):

```bash
bash tools/ops/update-safe.sh --apply --backup "$BACKUP_FILE"
bash tools/ops/update-safe.sh --apply --backup "$BACKUP_FILE" --migrate
bash tools/ops/update-safe.sh --apply --backup "$BACKUP_FILE" \
  --start-workers --start-hubspot-workers
```

`--migrate` executa primeiro `migrate --pretend` e depois `migrate --force`;
sem essa opção, nenhuma migration é executada. Produção exige adicionalmente
`--allow-production`. `--start-workers` inicia worker padrão e scheduler;
`--start-hubspot-workers` exige `--start-workers` e é uma autorização separada
para consumir filas HubSpot. O worker de campanhas nunca é iniciado por esse
script: avalie a cota SMTP e o estado das mensagens antes de liberá-lo.

O script não chama `key:generate`, `down -v`, `migrate:fresh`,
`migrate:refresh`, `queue:clear` nem `cache:clear` geral. Não faz backup,
deploy, push, limpeza de fila ou rollback automático.

## Rollback e migrations

Mantenha identificados o commit atual e o último commit conhecido como bom.
Em falha de código, volte a publicar a versão conhecida como boa pelo processo
de release aprovado; não altere manualmente o worktree operacional. Se uma
migration não for compatível com rollback de aplicação, restaure o backup
somente conforme o procedimento aprovado para o cluster de homologação ou
produção. Prefira migrations aditivas e estratégia expand/contract; não remova
colunas enquanto versões antigas ainda possam executar.

## Webhooks

`ngrok` aponta exclusivamente para `campaigns-unsubscribe-gateway`. Esse Nginx
permite somente `/email/descadastro/<id>.<hmac>` e responde 404 às demais rotas.
O webhook HubSpot continua separado em `/webhooks/hubspot`, protegido pelo
middleware `hubspot.signature`; não publique esse endpoint pelo gateway de
descadastro nem altere `APP_URL` para apontar ao túnel. Uma exposição externa
do webhook exige ingress HTTPS separado, revisão de assinatura e autorização
operacional.
