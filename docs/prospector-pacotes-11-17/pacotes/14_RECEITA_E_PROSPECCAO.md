# PACOTE 14 — Receita Federal, base por competência, importações e Prospecção

## Estrutura existente

- Python em `tools/receita/`: `api.py`, `prospect_query.py`, scripts de download/transformação/consulta, fixtures e testes `test_*.py`.
- Laravel: `app/Services/Providers/ReceitaLocalCnpjGroupProvider.php`, `app/Services/CompanyGroupEnrichmentService.php`, `app/Services/Prospecting*`, `app/Jobs/ResearchCompanyExports.php`, `app/Console/Commands/RecoverStaleExportResearch.php`, `app/Services/ImportQueueService.php`, `app/Jobs/EnrichImportItem.php`.
- Dados grandes da Receita **fora do Git** (`tools/receita/data`), convertidos em Parquet e consultados por DuckDB. PostgreSQL operacional armazena apenas empresas relevantes e estados de processo. CNPJ alfanumérico já suportado.

## Mudanças exigidas

1. **Health verdadeiro**: `api.py::health()` não pode declarar `ok` apenas porque arquivos esperados existem. Implementar critério barato de integridade: manifesto de competência validado, metadados de schema/contagem/marca de conclusão, e amostra verificada. Health não deve tentar varrer terabytes por chamada. Falso positivo com arquivos corrompidos -> `503`, com motivo seguro e sem vazar caminho secreto.
2. **Publicação atômica de competência**: processamento em diretório de staging, validação de entradas/tabelas/colunas, índices e amostras; gerar `manifest.json` com versão/schema/checksums/contagens e commit marker; só depois trocar ponteiro da competência ativa por operação atômica. Em falha, competência antiga continua ativa, sem apagar dados. Não ativar nova competência real automaticamente.
3. **Consultas de Prospecção**: examinar `tools/receita/prospect_query.py` e o fluxo que chama até 20 lotes; eliminar reprocessamento de estatísticas por página. Projetar cache/versionamento por competência e filtros normalizados, paginação por cursor ou pré-agrupações persistentes com orçamento de CPU/memória. O cache deve invalidar quando a competência ativa mudar. Nunca carregar todo o Parquet em memória Laravel.
4. **Controle de lote e importação**: ingestão em blocos com limites configuráveis, filas Redis, progresso, reprocessamento idempotente, retorno HTTP rápido, recovery de lotes interrompidos. Priorizar `where` de elegibilidade **antes** de `limit` e nunca fixar nas primeiras falhas definitivas para sempre.
5. **Pesquisa de exportação**: em `RecoverStaleExportResearch`, corrigir fome dos itens elegíveis (seleção da fila deve atingir casos recuperáveis posteriores). Conferir `ExportResearch*`/identificador do site: casar domínio e subdomínio real, não similaridade de texto; não declarar exportação direta/indireta como confirmada sem fonte verificável. Preservar orçamento Tavily/custo e cooldown.
6. **Normalização**: para CNPJ, não `preg_replace('/\\D/', '', ...)` quando o dado pode ser alfanumérico; usar `App\Support\Cnpj` e normalizador Python existente. Manter zero inicial de CNAE, informações de matriz/filial e status textual/código conforme Pacote 10.
7. **Observabilidade**: métricas agregadas por competência, query, rows scanned/returned, cursor, tempo e filas, sem registrar nomes/e-mails/telefones pessoais em logs.

## Testes obrigatórios

- Python: `python -m unittest discover -s /app -p 'test_*.py'` no container da Receita (ou comando equivalente após conferir Dockerfile).
- Novo fixture: arquivos com nomes esperados mas conteúdo inválido => `GET /health` deve responder `503`; manifesto validado + consulta de raiz => `200`.
- Testar uma base pequena de CNPJs numéricos e alfanuméricos, CNAE com zero inicial, filtro matriz/filial, paginação de duas páginas sem registros perdidos/duplicados, ativação de competência com falha simulada.
- `tests/Feature/Prospecting/`, `tests/Feature/Imports/`, `tests/Feature/Companies/` e testes de pesquisa/recovery.
- Teste da seleção de 200 falhas definitivas precedendo um item recuperável: recuperável deve ser visitado.
- Teste domínio `exemplo.com.br` vs `outro-exemplo.com.br` com similaridade textual: rejeitar vínculo inseguro.

## Critério de aceite

- Sem download de base nacional nos testes.
- Sem migração ou troca real da competência ativa.
- `/health` não mente sobre dataset corrompido, e consultas/caches respeitam a competência.
- Ganhos de performance documentados antes/depois em fixture e, quando autorizado, em ambiente de homologação.
- Mantidos os tratamentos de erro HTTP 404/429/5xx, timeout e espera para retry nas integrações.
