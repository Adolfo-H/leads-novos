# Organização do código — candidato de validação

Base: ZIP PROSPECTOR_CODIGO_COMPLETO_20261113_164725.zip (2026-11-13).
Este documento acompanha uma proposta aplicada somente à cópia de revisão. Não comprova instalação,
CI ou homologação do sistema operacional.

## Convenções

Classes e arquivos próprios passam a representar responsabilidades, não o número do pacote que os criou.
Consulte MAPA_DE_RENOMES.csv nesta pasta para o mapeamento exato. Não faça substituições globais adicionais.
Quando havia colisão, mantivemos implementações diferentes com nomes descritivos (base, refinamento,
previsão, cronograma, legado), sem presumir que um arquivo mais antigo fosse dispensável.

Os seletores CSS, dados de tela, assinaturas Artisan, nomes de rotas, URLs, tabelas, migrations,
chaves de sessão/cache e identificadores de protocolos não foram renomeados por estética.
A view `campaigns.wizard` é um arquivo; a rota `campaigns.wizard-v93` permanece compatível.

Quatro classes ainda mantêm o sufixo por compatibilidade: ResearchWhatsappCompanyWebV87,
ResearchWhatsappExternalCompanyV14, WhatsappCompanyLinksV16 e WhatsappManualVerificationV16.
As duas primeiras podem estar em payloads de fila; as duas últimas possuem identidade Livewire.
Uma migração desses nomes exige validar filas/sessões/processos e não faz parte deste lote.

## Documentação histórica

Os relatórios anteriores em docs/auditoria e docs/prospector-pacotes-11-17 foram preservados como histórico.
Se um comando antigo citar um arquivo que mudou de nome, use o mapa desta pasta. Não execute instaladores
antigos para restaurar nomes ou paths: o aplicador novo tem seu próprio manifesto e backup externo.
Inventário é cobertura de arquivos, não evidência de revisão semântica completa.

## Limpeza

Três assets idênticos por SHA-256 foram substituídos por cópias canônicas; dois arquivos vazios,
um protótipo instalável histórico e o checkpoint Git antigo saíram do código ativo da proposta.
Material local de instalação e backups deve ficar fora do checkout. Nunca apague storage, migrations,
.env, credenciais, bases Receita, volumes ou histórico Git como parte dessa organização.

## Git e validação

`scripts/git/preflight.sh` é somente leitura e verifica nomes potencialmente sensíveis no staging.
Ele não detecta todo segredo e não prepara commits. Faça adições seletivas, incluindo remoções de caminhos
renomeados, somente após revisão dos diffs, CI completo e testes de interface. Não use git add . ou reset.

O pipeline existente ganhou os testes offline de DSN Python e do classificador WhatsApp. O resolver de
associações HubSpot ganhou paginação validada e o teste de paginação deixou de estar dentro de outro teste.
Não houve implantação, envio real ou execução de migration. Ainda é necessário validar a implementação
real com vendor/runtime do projeto e executar o build, os testes de navegador e os cenários PostgreSQL.
