# Regras obrigatórias para qualquer implementação

## Nunca executar automaticamente

- Escritas no HubSpot real, criação de negócios, contatos, empresas, atividades ou mudanças de estágio.
- Envio SMTP real, liberação automática de campanhas, reenvio de mensagens com resultado incerto.
- Recálculo massivo de scores e atualização de empresa/filiais de produção.
- Mudança da competência ativa da Receita Federal ou remoção de arquivos Parquet/ZIP de dados.
- `php artisan migrate:fresh`, `php artisan migrate:refresh`, `docker compose down -v`, `git reset --hard`, `git clean -fd`, `git push --force`, `npm audit fix --force`.
- `php artisan key:generate` em `.env` já existente; `composer setup` em instalação operacional, pois o script atual inclui `key:generate`.
- Exclusão de migrations, pastas do Laravel e volumes sob a justificativa de limpeza.

## Sempre exigir

1. Para mudança com dados: plano de reversão, dry-run, amostra e confirmação separada de `--apply`.
2. Para HubSpot: `Http::fake`, gravação local da identidade da operação, tratamento de resposta incerta e teste de não duplicação.
3. Para campanhas: `Mail::fake`, status `uncertain` sem retry automático, travas consistentes, cota validada **na tentativa**, opt-out/reply/bounce verificados antes do envio, configuração live sempre desligada por padrão.
4. Para Receita: fixtures pequenas com CNPJ numérico e alfanumérico; validação atômica por competência, sem carga completa de dados em testes.
5. Para Docker: validar `docker compose config`, saúde por componente e reinício gracioso dos workers em fluxo de atualização separado do simples startup.
6. Para mudanças visuais: capturas e testes funcionais existentes; remover CSS só com evidência de não utilização.
7. Para Git: respeitar branch e alterações existentes, `git diff --check`, verificar arquivo staged e não incluir segredos.

## Preservar decisões do projeto

- A sincronização comercial no HubSpot trabalha com `Company`, `Contact`, `Deal` e associações; isso não implica criar o objeto nativo `Lead`.
- O responsável interno no Prospector não é necessariamente o `owner_name` no HubSpot.
- O dashboard lê o espelho local; ele não deve passar a disparar escritas no HubSpot.
- A confirmação de exportação precisa de evidência ou validação humana; localização, CNAE e proximidade de portos não são confirmação.
- O filtro de cliente deve considerar a raiz do CNPJ com vínculo fiscal confiável; não extrapolar por domínio ou razão social semelhante.
- Não remover o canal Receita do detalhe, nem confundi-lo com uma pessoa real.

## Orientações operacionais

- Pode criar migrações **aditivas** com `down()` simétrico, mas não executá-las no banco real sem aprovação; testar em banco de teste.
- Evitar imprimir `Http::response()->body()` de integrações, nomes, e-mails ou contatos em logs.
- Confirmar que testes usem banco `testing` ou SQLite em memória; nunca dar `migrate:fresh` na conexão real `pgsql/laravel`.
- Fixar flags de produção novas como `false` e documentar aprovação da ativação.
- Separar "erro de código", "dependência de rede" e "pendência de autorização" no relatório.
