# Controle de implementação — preencher no Codex

O Codex deve registrar a cada etapa: **Implementado / Testes aprovados / Bloqueado / Pendente de autorização**. Não marque apenas "concluído" sem testes verdes.

| Pacote | Preflight | Código e testes | PHPUnit/Pest | PHPStan/Pint | Reversão | Ação real separada |
|---|---|---|---|---|---|---|
| 11 ICP | [ ] | [ ] | [ ] | [ ] | [ ] | Recalcular scores reais [ ] |
| 12 HubSpot | [ ] | [ ] | [ ] | [ ] | [ ] | Escritas HubSpot/endpoint externo [ ] |
| 13 Campanhas | [ ] | [ ] | [ ] | [ ] | [ ] | Liberar qualquer SMTP [ ] |
| 14 Receita | [ ] | [ ] | [ ] | [ ] | [ ] | Nova competência/dados [ ] |
| 15 Docker | [ ] | [ ] | [ ] | [ ] | [ ] | Deploy/reciclar workers [ ] |
| 16 Segurança | [ ] | [ ] | [ ] | [ ] | [ ] | Atualização/remover arquivos históricos [ ] |
| 17 Homologação | [ ] | [ ] | [ ] | [ ] | [ ] | Produção [ ] |

**Se a execução parar**, reúna apenas: etapa, comando, trecho do erro, `git status -sb`, alterações relevantes do diff. Remova credenciais, tokens, payloads HubSpot, e-mails reais e qualquer dado pessoal antes de compartilhar.

## Fluxo operacional recomendado

- Testes de cada pacote no banco `testing` ou SQLite em memória.
- Nenhum teste usa `migrate:fresh` na base real PostgreSQL `laravel`.
- O ambiente de homologação simula HubSpot/SMTP/Receita, com mocks.
- Ações que causam efeitos externos precisam de autorização separada; o ZIP, por si só, não autoriza.
- Preferir 2 checkpoints de código: após 11–13 e após 14–17, em vez de commits após cada arquivo.
