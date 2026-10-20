# PACOTE 17 — Homologação integral do Prospector ExportControl

## Objetivo

Comprovar que, após os Pacotes 11–16, o sistema mantém fluxos integrais, não somente testes isolados. Nenhum envio de campanha/POST HubSpot real é feito automaticamente.

## Cenários E2E obrigatórios

1. **Login e permissões:** gerente, vendedor, sem login; acesso permitido/negado a empresas, contatos, campanhas, dossiês, exportações, configurações e webhooks.
2. **CNPJ de entrada:** digitar CNPJ tradicional/alfanumérico; importar lista CSV/Excel sintética com cabeçalho e duplicidades; validar normalização; processar em fila e registrar progresso/retry.
3. **Matriz e filiais:** ler Receita local fixture pequena, atualizar empresa/estabelecimentos, preservar CNAE existente quando API parcial, calcular ICP `v3` e Score SDR com fatores auditáveis.
4. **CRM check:** comparar CNPJ completo e raiz, bloquear cliente ativo e oportunidade em aberto, diferenciar descartado/recusado/oportunidade futura por regras existentes.
5. **Pesquisa de exportação:** trazer evidências públicas simuladas (site, notícia, doc), diferenciar direta, indireta, trading, probabilidade e confirmação. Sem identificar exportação a partir de CNAE sozinho.
6. **Contatos:** lista 24k em base de benchmark ou fixture escalada, filtros cliente, negócios, manual, Receita, busca e paginação duas etapas; seleção e detalhes, confirmação WhatsApp, nenhuma associação fiscal insegura, controles de acesso.
7. **HubSpot:** `Http::fake` para criar/associar Company/Contact/Deal, perda de resposta depois de POST, recovery sem duplicar, webhook válido/inválido/repetido, projeção local, histórico; sem requisições reais.
8. **Campanhas:** construir rascunho com contatos elegíveis fictícios, editar destinatários sem apagar vínculo de sequência, reply ao primeiro e-mail bloqueia follow-up, descadastro e bounce, cota com worker atrasado, SMTP `Mail::fake`, resultados incertos sem reenvio, nenhuma campanha ativada.
9. **Resiliência:** Redis indisponível, Receita health falho, backend demorado, worker pausado, reinício de versão, limites de recursos; falha visível e recuperável.
10. **UI e performance:** dashboard, empresa, leads, Contatos, campanhas, integrações em desktop/mobile; estados carregando/vazio/erro; tempos de resposta no Chrome Network e SQL no PostgreSQL antes/depois. Não fixar tempos como garantia em hardware desconhecido; registrar mediana e p95 em múltiplas execuções.

## Testes automatizados

- Suites Pest Laravel relevantes, incluindo `tests/Feature/Contacts`, `Companies`, `HubSpot`, `Campaigns`, `Prospecting`, `Imports`, `Infrastructure`, `Security`, `Integrity`, `Dashboard`, `Leads`.
- Tests Python Receita e IMAP por fixtures.
- Pint check, PHPStan, `git diff --check`, build JS, npm/composer audits.
- Testes isolados PostgreSQL/Redis quando compatíveis com `testing`; NÃO rodar em `pgsql/laravel` com `migrate:fresh` ou reset. Docker `compose config`.
- Se faltarem testes end-to-end de navegação, criar testes Pest/Livewire para fluxos mais críticos. Para browser real, escolher ferramenta já disponível no projeto; não instalar framework grande sem justificativa.

## Relatório final (arquivo em docs, sem PII)

- `docs/prospector-pacotes-11-17/RELATORIO_FINAL.md` deve listar: alterações por pacote, testes, arquivos, migrations preparadas/não aplicadas, indicadores, flags desativadas, riscos conhecidos, backups e ordem de deploy.
- Uma checklist separada de **APROVAÇÃO HUMANA** para: migration PostgreSQL real, recálculo scores reais, sincronização HubSpot real, publicar competência Receita, liberar SMTP comercial, deploy/alteração de containers.
- Descrever rollback por componente e restauração de dados, incluindo teste de restaurar backup em ambiente isolado.
- Marcar PASS/FAIL/BLOQUEADO explicitamente; não declarar homologado se só houve testes unitários.

## Aceite de entrega unificada

- Pacotes 11–16 implementados com testes em ambiente isolado e sem side effects externos; Pacote 17 prova invariantes.
- `composer ci:check` e `npm run build` aprovados (falha DNS externa classificada, não ocultada).
- Nenhum novo e-mail real enviado, nenhum negócio real criado, nenhum dado Receita apagado, nenhuma `APP_KEY` regenerada.
- Git com mudanças rastreáveis, sem segredos. Antes de push/merge para main, revisão humana das migrations, alterações de jobs/campanhas e scripts operacionais.
