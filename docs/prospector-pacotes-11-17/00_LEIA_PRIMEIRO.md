# Prospector ExportControl — Entrega unificada dos Pacotes 11–17

**Base revisada:** GitHub `Adolfo-H/leads-novos`, branch `main`, commit `5b43e22fd330096706dd2e5529a48e2e80a46b75` (20/10/2026). O usuário informou que os Pacotes 01–10 foram aplicados e consolidados nesse commit. **Sempre ler o código local atual**: ele é a fonte de verdade quando divergir da fotografia revisada.

Este material reúne, em UMA entrega, **as especificações executáveis pelo Codex, roteiros de implementação, caminhos reais, testes de aceite, utilitários de diagnóstico e instruções de reversão** para os Pacotes 11 a 17. O Codex deverá implementar os arquivos PHP/Python/Shell no projeto local; este ZIP **não contém alterações já aplicadas nem promete que todo código da aplicação está pronto para produção**. Não há execução automática de integração com HubSpot, campanhas nem mudanças no banco.

## Como instalar (sem arquivos soltos na raiz)

1. Salve o ZIP em `~/projetos` (pasta irmã do projeto, não dentro dele).
2. No Ubuntu/WSL:

```bash
cd ~/projetos/prospector-exportcontrol
unzip -n ../PROSPECTOR_PACOTES_11_A_17_UNIFICADO.zip -d .
```

A única pasta nova será `docs/prospector-pacotes-11-17/`.

3. Rode o diagnóstico inicial:

```bash
bash docs/prospector-pacotes-11-17/scripts/preflight.sh
```

4. Abra o Codex no projeto e cole o conteúdo de **`01_PROMPT_UNICO_PARA_CODEX.md`**. Ele encontrará todos os pacotes em `pacotes/` e executará na sequência.

**Importante:** o Codex deve implementar **uma etapa por vez**, executando testes e criando um ponto de controle antes de avançar. Não execute `--apply`, migrações, envios reais, sincronização real, limpeza irreversível ou publicação para produção sem autorização explícita.

## Conteúdo

| Pasta/arquivo | Para quê |
|---|---|
| `01_PROMPT_UNICO_PARA_CODEX.md` | Texto único para colar no Codex; cobre os sete pacotes e exige parada em falhas |
| `02_REGRAS_DE_SEGURANCA.md` | Restrições de código, dados, HubSpot, Receita, campanhas e Git |
| `pacotes/11_*.md` até `17_*.md` | Instruções por módulo, com arquivos reais, lógica, testes e aceites |
| `scripts/preflight.sh` | Diagnóstico local da branch, ferramentas e configuração, sem exibir credenciais |
| `scripts/validar.sh` | Testes direcionados por etapa e CI completo; não executa migrações |
| `scripts/auditar_icp.php` | Auditoria inicial SOMENTE LEITURA da versão dos scores ICP e códigos cadastrais |
| `scripts/auditar_hubspot.php` | Contagens SOMENTE LEITURA de sincronizações e identificadores remotos |
| `modelos/RELATORIO_DE_ETAPA.md` | Template obrigatório de resultado para Codex |

## Expectativa correta de execução

- Não colar 7 conjuntos de patches em produção simultaneamente.
- Sim: colocar **um ZIP** no projeto, pedir ao Codex **todo o ciclo de desenvolvimento** e devolver resultados/erros da etapa em que parar.
- O Codex deve preservar o código dos Pacotes 01–10 e só avançar com os testes de regressão verdes.
- A aplicação pode ter mudado depois do commit-base. Se tiver mudado, o Codex deve examinar a diferença e ajustar o plano antes de substituir arquivos.
- Se surgir erro de teste, `phpstan`, `pint`, migration, Docker ou integração, **interromper antes de avançar** e entregar caminho, linha, comando, erro e patch proposto. Não usar `git reset --hard` para "limpar" o problema.

## Comandos úteis

```bash
# Auditorias seguras no banco local (não mostram contatos ou nomes de empresa)
./vendor/bin/sail artisan tinker --execute='require base_path("docs/prospector-pacotes-11-17/scripts/auditar_icp.php");'
./vendor/bin/sail artisan tinker --execute='require base_path("docs/prospector-pacotes-11-17/scripts/auditar_hubspot.php");'

# CI direcionado após cada pacote
bash docs/prospector-pacotes-11-17/scripts/validar.sh 11

# CI geral após todas as implementações
bash docs/prospector-pacotes-11-17/scripts/validar.sh all
```

## Registro de origem

Consulta de leitura ao repositório em 20/10/2026: `main` na revisão `5b43e22fd330096706dd2e5529a48e2e80a46b75`. Também foram inspecionados os ZIPs de 19/10 e os Pacotes 01–10 compartilhados anteriormente. O Código vigente no seu WSL sempre terá prioridade sobre ZIPs antigos.
