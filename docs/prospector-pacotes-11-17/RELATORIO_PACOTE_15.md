# Relatório — Pacote 15

## Implementado

- Compose agora declara healthchecks para Laravel, PostgreSQL, Redis, Receita e
  gateway; Laravel e workers aguardam PostgreSQL/Redis saudáveis. Receita
  continua opcional para o boot Laravel porque uma competência ativa pode não
  existir.
- Os serviços de aplicação usam rotação Docker `json-file` limitada a 10 MB e
  cinco arquivos. O canal Laravel `daily` tem retenção configurável; os logs
  de startup Vite/HubSpot têm rotação limitada.
- `start.sh` espera saúde de PostgreSQL, Redis e Laravel, informa estado dos
  componentes e espaço disponível no host/volume Receita, inicia Receita sem
  torná-la requisito do Laravel e não altera réplicas de workers já ativos.
  A sincronização inicial HubSpot é desligada por padrão e, se explicitamente
  habilitada, exige limite de 1 a 100 empresas.
- `tools/ops/update-safe.sh` é dry-run por padrão. O modo apply requer backup
  legível fora do repositório, worktree limpo, ambiente saudável e workers
  previamente drenados/parados; exige flags explícitas para migrations,
  ambiente de produção e workers HubSpot. O worker de campanhas não é iniciado
  pelo script. Falhas após entrar em manutenção tentam executar `artisan up`.
- Scheduler aplica `onOneServer` e `withoutOverlapping` a todos os eventos.
  Heartbeats monitoram scheduler e workers padrão/HubSpot; `ops:health` informa
  esses estados e a profundidade agregada das filas sem ler payloads.
- O gateway/ngrok continua restrito a descadastro. A rota `/webhooks/hubspot`
  permanece separada e exige o middleware de assinatura.
- Procedimentos de startup, atualização, backup/restore, rollback, health e
  separação de webhooks estão em [`tools/ops/README.md`](../../tools/ops/README.md).

## Validação

- Infrastructure: **15 testes passaram, 111 assertions**.
- Pint: **5 arquivos aprovados**.
- PHPStan: **sem erros**.
- `docker compose config --quiet`: aprovado.
- `bash -n start.sh tools/ops/update-safe.sh`: aprovado.
- `git diff --check`: aprovado. Git exibiu apenas avisos de line endings em
  arquivos preexistentes fora deste pacote (`tools/receita/api.py` e
  `tools/windows/Iniciar Prospector.cmd`).
- O teste de dry-run usa um comando Docker fake e comprova que não executa
  `exec`, `up`, `restart` ou migrations; uma tentativa de apply sem backup é
  recusada antes de qualquer operação de container.

## Limites operacionais

Não foram executados `start.sh`, modo apply, migrations, criação/verificação de
backup, restore, restart/recriação de containers, deploy, chamadas HubSpot ou
envios de e-mail. O restore foi documentado, mas não pôde ser testado em
homologação sem um alvo isolado autorizado.
