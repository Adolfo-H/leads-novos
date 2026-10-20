# Relatório — Pacote 14

## Implementado

- Publicação de releases Receita a partir de staging, com validação de schema,
  amostras, contagens, checksum SHA-256, `manifest.json` e marcador `_SUCCESS`.
  As versões são identificadas por conteúdo e uma versão existente não é
  sobrescrita. A troca de `/real-data/processed/active` só ocorre com
  `--activate`, depois da publicação e validação; falhas mantêm o ponteiro
  anterior.
- `/health` exige manifesto/commit válidos, metadados de tabela, tamanho e
  marcadores Parquet, além de consultar schema e amostra de cada tabela. Retorna
  `503` com motivo seguro para datasets ausentes ou inválidos e não expõe
  diretórios locais.
- A publicação pré-calcula `active_group_stats.parquet`. Prospects reutiliza
  essa tabela, impõe limites DuckDB de threads/memória, ordenação estável e
  devolve `dataset_version` e `rows_scanned`. O Laravel envia a versão em cada
  página subsequente e rejeita mudança de competência com HTTP 409.
- Importação CNPJ tem limite configurável de linhas; o dispatch percorre itens
  prontos em chunks e com teto por execução. `imports:dispatch-ready` retoma
  sobras em execuções agendadas, e a recuperação stale tem limite configurável.
  Logs de consultas e filas usam apenas competência, cursores/IDs e contagens
  agregadas, sem dados pessoais.
- A recuperação da pesquisa de exportação filtra falhas definitivas e registros
  sem empresa antes do limite; há teste com 200 falhas definitivas antes do item
  elegível. O matcher de domínio aceita igualdade ou relação real de
  subdomínio, não rótulos textuais compartilhados.
- Instruções de staging, validação e ativação explícita estão em
  [`tools/receita/README.md`](../../tools/receita/README.md).

## Validação

- Laravel: **93 testes passaram, 401 assertions** nos conjuntos Prospecting,
  Imports e testes direcionados de Companies/Receita/recovery/matcher.
- Python Receita: **10 testes passaram**, sem downloads externos.
- Pint: **540 arquivos aprovados**.
- PHPStan: **290 arquivos analisados, nenhum erro**.
- `docker compose config --quiet` e `git diff --check`: aprovados.
- Benchmark sintético medido pelo `cumulative_rows_scanned` do DuckDB:
  agregação por página anterior — **5** linhas de estabelecimentos; leitura da
  pré-agregação persistida — **4** linhas de grupos. É uma fixture pequena, não
  representa tempo de parede nem homologação em escala.

## Limites operacionais respeitados

Não foram executados downloads da base nacional, ativação de release real,
chamadas HubSpot, envios comerciais, migrations operacionais nem recálculos em
massa. Os testes de ativação usaram somente diretórios temporários.
