# PACOTE 16 — Segurança, dependências e organização final

## Áreas a inspecionar

- `package.json`, `package-lock.json`: auditoria anterior encontrou transitivas afetadas de ferramentas dev/build (`shell-quote`, `tinypool`, `source-map-js`). **Reexecutar `npm audit` na versão atual**, não assumir que continuam afetadas ou que versões antigas ainda estejam em uso.
- `composer.json`, `composer.lock`: `composer audit` e versão PHP/Laravel; nunca substituir lock sem validar scripts/framework.
- `app/Http/Middleware`, `app/Policies`, `bootstrap/app.php`, `routes/web.php`, `routes/console.php`: autorizações para Contatos, HubSpot, campanhas e gerência.
- `resources/css/app.css`, `resources/css/contacts-reference-v3.css`, `resources/css/contacts-receita-v4.css`, demais CSS, `resources/views`: redundância / estilos acumulados.
- `tools/`, `docker/`, `.github/workflows`, `.gitignore`, `storage/`.

## Código e manutenção

1. Rodar auditorias `npm audit --json` ou equivalente, `composer audit`, PHPStan, Pint, Pest, testes Python e build. Classificar risco entre dev/build e runtime. Atualizar transitivas **via resolução de dependências compatível**; sem `npm audit fix --force` ou upgrades de framework sem plano. Preservar `package-lock.json` consistente e `composer.lock`.
2. Verificar autenticação, separação gerente/vendedor, CSRF, rate limit, assinatura webhook, validação de upload, proteção de URLs de descadastro, escopo de consulta a dados e logs. Criar testes de acesso negado para roles indevidas. Não imprimir tokens em saídas.
3. Garantir que `.env.example` contenha placeholders, nunca credenciais; `.env`, certificados, bases Parquet, logs, `.sqlite`, backups e diretórios privados não sejam rastreados. `git ls-files` e ignore devem ser revisados com cuidado.
4. **Limpeza guiada por referências**: antes de remover arquivos CSS/scripts ou classes, procurar referências via `rg`, imports, rotas, `vite.config.js`, testes e workflows; somente remover sob evidência e com teste de regressão. Priorizar mover scripts históricos de correção para um arquivo externo versionado com README quando realmente necessários. Nunca excluir migrations aplicadas.
5. CSS: propor tokens de design ExportControl reutilizáveis, dividir por módulo quando melhorar legibilidade, preservar precedência/contraste, não reformular simultaneamente todo o dashboard sem aprovação visual. Comprovar build e screenshots antes/depois.
6. Reduzir arquivos gigantes e código duplicado gradualmente, preservando APIs internas e testes; documentar decisões arquiteturais atuais para evitar criar um segundo `CompanyService`/`ContactDirectory` equivalente.
7. Atualizar instruções de backups, rotação de logs, retenção de arquivos HubSpot e limites de dados no repositório. Evitar remoção agressiva de `storage` ou arquivos da Receita.

## Testes e critérios

- `./vendor/bin/sail composer ci:check`, `./vendor/bin/sail composer audit` (se suporte e rede), `npm audit`, `npm run build`.
- Testes de autorização em `tests/Feature/Security`, `tests/Feature/HubSpot`, `tests/Feature/Campaigns`, `tests/Feature/Contacts`.
- CSS/SVG/JS sem alteração visual acidental; testes das telas mobile/desktop e responsividade.
- Nenhuma credencial em `git diff --cached`, nenhum arquivo operacional apagado sem inventário/backup.
- Relatório final separado: arquivos removidos, arquivos mantidos por dependência, arquivos arquivados e motivo de cada decisão.
