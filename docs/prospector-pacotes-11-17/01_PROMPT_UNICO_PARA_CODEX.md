# PROMPT ÚNICO PARA O CODEX — PROSPECTOR EXPORTCONTROL (PACOTES 11 A 17)

Você está trabalhando no repositório Laravel **Prospector ExportControl** (`Adolfo-H/leads-novos`) aberto no WSL, em `~/projetos/prospector-exportcontrol`.

A prioridade é **implementar, não só aconselhar**: leia TODOS os arquivos `.md` nesta pasta `docs/prospector-pacotes-11-17/`, principalmente `02_REGRAS_DE_SEGURANCA.md` e `pacotes/11_*.md` a `pacotes/17_*.md`. Cada pacote contém caminhos reais do código existente, comportamento desejado, cenários de teste e critérios de aceite. A base revisada foi a `main` commit `5b43e22`, mas LEIA o estado atual e adapte aos arquivos realmente presentes. Não sobrescreva modificações preexistentes.

## MODO DE EXECUÇÃO

1. **Inspecione primeiro**: `git status -sb`, `git log -1 --oneline`, arquivos relevantes, migrations, modelos, jobs, comandos, rotas, filas, flags existentes; rode `scripts/preflight.sh` e faça um inventário de riscos. Não imprima `.env`, senhas, tokens, contatos, mensagens pessoais, credenciais ou dados comerciais reais.
2. **Proteja o estado**: se o projeto não estiver limpo, não sobrescreva nem faça reset; reporte as diferenças e peça instruções. Se estiver limpo, sugira/crie branch local `feature/pacotes-11-17` (não force, não mude branch se já houver trabalho em andamento). Não faça push sem o pedido do usuário.
3. **Execute sequencialmente**, começando por `pacotes/11_ICP.md`, depois 12, 13, 14, 15, 16 e 17. Faça modificações reais de código e testes, não apenas documentação. Código final de cada arquivo deve ser completo e compatível com classes existentes. Prefira mudanças pequenas. Nunca apague um componente inteiro por parecer duplicado sem conferir referências e testes.
4. **Cada etapa possui dois níveis**: (A) implementação/testes com mocks ou em ambiente isolado; (B) ação operacional real. Somente (A) é autorizada por este prompt. **(B) exige confirmação humana específica**: recálculo de scores reais, criação/atualização no HubSpot real, envio de e-mails, migrações com risco operacional, troca de competência da Receita e deploy.
5. **Ao terminar uma etapa**, execute os testes do próprio pacote, PHPStan, Pint check e `git diff --check`; use `bash docs/prospector-pacotes-11-17/scripts/validar.sh <numero>`. Se o comando de build depender de acesso à rede e falhar por DNS, registre como bloqueio externo, sem afirmar que passou.
6. **Interrompa na primeira falha**, mostre o erro resumido, arquivos/linhas, motivo provável e correção, e aguarde resposta do usuário. Não continue para o próximo pacote com testes vermelhos ou erro encoberto.
7. **Registre resultados** ao final de cada pacote seguindo `modelos/RELATORIO_DE_ETAPA.md`, com caminhos afetados, testes que passaram, testes não executados e riscos. Preserve logs sem credenciais; evite arquivos de log na raiz.
8. **Não faça sete commits desnecessários**. Faça commits somente depois de marcos estáveis, com aceite e autorização do usuário. Não force push, não reescreva histórico.

## GARANTIAS DE NEGÓCIO (NÃO VIOLAR)

- Dados primários Receita/Parquet/DuckDB não devem ser misturados com o espelho HubSpot ou copiados em massa para o PostgreSQL operacional.
- Não inferir relação fiscal por nome/domínio entre pessoas HubSpot e canais cadastrais Receita. Preservar CNPJ alfanumérico.
- Manter clientes e oportunidades abertas protegidos da prospecção automática; ``descartado`` não volta sozinho; ``recusou`` e ``oportunidade futura`` obedecem às regras existentes.
- Preservar os filtros, a ordem e os totais validados pelos Pacotes 01–10 (24.846 contatos no diagnóstico de 20/10/2026; esse número é referência, não fixo).
- Nunca criar um segundo negócio HubSpot automaticamente se houve tentativa POST com resultado remoto incerto. Não inventar campo de idempotência na API HubSpot.
- **Campanhas desligadas por padrão**, sem habilitar `CAMPAIGN_MAIL_LIVE_ENABLED`, `CAMPAIGN_REPLY_SEND_ENABLED` ou campanha piloto. Nada de SMTP real, envio em massa, falsos opt-outs, limpeza de reservas incertas ou reenvio automático incerto.
- Não gerar `APP_KEY` novamente, não apagar volumes/banco, não executar `migrate:fresh`, `migrate:refresh`, `docker compose down -v`, `git clean -fd`, `git reset --hard`, `npm audit fix --force`, nem aumentar cotas de e-mails automaticamente.
- Não adicionar scripts soltos à raiz; crie novos comandos nas pastas padrão do Laravel, documentação em `docs/` e utilitários em `tools/` ou `scripts/` organizados.

## RESULTADO FINAL ESPERADO

Implementação real e testes dos Pacotes 11–17 (quando possível sem serviços reais), acompanhados de checklist de homologação, plano de reversão por módulo e relatório do que ainda requer autorização humana. Se algum pacote depender de propriedades/credenciais, API externa, env ou schema que não está presente, não invente: bloqueie só a operação correspondente e descreva o requisito pendente. **Priorize qualidade e integridade, não apenas a quantidade de linhas alteradas.**

**Inicie pelo pré-voo e pelo Pacote 11 agora.**
