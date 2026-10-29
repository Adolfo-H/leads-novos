# Prospector ExportControl — marco A20: qualidade de desenvolvimento

Esta etapa verifica o código para iniciar a **homologação de uso real**, não declara que o sistema já pode ser publicado na internet.

## O que o A20 valida

- Testes Python de recuperação IMAP **offline** (`unittest` com caixas falsas).
- Testes Python da Receita (`unittest`, sem publicação ou ativação de bases).
- Compilação dos assets pelo Vite.
- Carregamento das rotas Laravel.
- `composer ci:check` (Pint, PHPStan e testes Laravel).
- Git sincronizado com `main` antes de alterar os arquivos do A20.

O workflow do GitHub também executa os testes Python IMAP em push e pull request. Não executar comandos de importação ou recuperação reais apenas para homologar o A20.

## Comando reutilizável

Na raiz do projeto, depois de instalar as dependências e iniciar os containers, execute:

```bash
bash scripts/ci/quality-gate.sh
```

## Ainda obrigatório antes do uso comercial (A21–A25)

1. **Servidor e disponibilidade:** configurar um ambiente de implantação, separado do desenvolvimento, com banco/Redis persistentes, workers e scheduler gerenciados. O notebook só atende enquanto estiver ligado e acordado.
2. **Acesso remoto seguro:** HTTPS, acesso privado entre dispositivos, firewall, contas separadas e sessão protegida. Não expor diretamente PostgreSQL, Redis, Mailpit ou o aplicativo de desenvolvimento.
3. **HubSpot/Receita/e-mail:** conferir tokens e permissões sem copiá-los para relatórios; validar webhooks e reconciliação após notebook desligado; SMTP/IMAP e descadastro somente após homologação. Manter `CAMPAIGN_MAIL_LIVE_ENABLED=false` até aprovação explícita do piloto.
4. **Dados e recuperação:** criar backups periódicos criptografados fora do notebook e **testar uma restauração**. Proteger os arquivos Parquet da Receita, volumes PostgreSQL e configurações locais.
5. **Homologação com duas contas reais:** testar permissões de gestor/vendedor, navegação, busca, importação, filtros, campanhas apenas em modo seguro, filas, logs, reinício e uso simultâneo em navegadores diferentes.

## Limites

Um CI verde é evidência de qualidade **do código testado**; não prova que o banco PostgreSQL em uso, o desempenho em milhões de empresas, o acesso remoto, o backup ou as integrações reais estejam homologados. O A20 não altera `.env`, não executa migrations, não envia e-mails e não ativa campanhas.
