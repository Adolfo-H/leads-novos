# Implementação nativa / redesenho completo — cole este pedido em um agente com acesso ao repositório

Tenho o projeto Laravel/Livewire **Prospector ExportControl** em `~/projetos/prospector-exportcontrol`. Quero redesenhar **somente** a tela `/contatos/verificacao-whatsapp` (Central de Verificação), mantendo 100% da lógica e funções reais que já existem.

**LEIA os arquivos existentes antes de mudar QUALQUER COISA.** Identifique rota, view/componente Livewire, controller, classes Blade, CSS existente, serviços, migrations e testes. Consulte o estado Git antes. Trabalhe sobre o arquivo real e suas propriedades existentes; **não invente names de componentes ou propriedades Livewire**, não crie segunda implementação paralela, não substitua chamadas por `alert`, mock ou placeholders. Se alguma dependência não estiver disponível, não destrua a funcionalidade atual para simular.

## Layout final esperado

1. Cabeçalho compacto: `Central de verificação`, descrição curta, botão `Voltar para contatos`.
2. **Resumo geral** em seis KPIs alinhados: total de números da base, celulares, telefones fixos, números a revisar, confirmados manualmente, pendentes válidos. Números dinâmicos com os mesmos cálculos existentes. Não confundir índices por número distinto com registros de contato.
3. **Andamento da conferência técnica** em cinco KPIs: celulares cadastrados, já consultados, não consultados, encontrados (indício positivo), não localizados (indício negativo), barra de progresso. Preserve categoria `Inconclusivo` nos registros individuais e, se já existir, seu indicador separado; não some indevidamente os inconclusivos aos não localizados.
4. **Duas contas WhatsApp Web** lado a lado com status, número de conexão, regra de partição (IDs pares/ímpares), botão da consulta por lote de cada conta, seleção de quantidade 5/10 respeitando limite existente. Exibir claramente estado de carregamento e erros reais, sem simular.
5. **Últimas consultas** com número, resultado técnico, conta consultada, data/hora e acesso aos detalhes; área compacta e rolável.
6. **Filtros** com busca, tipo, estado do WhatsApp, origem e estado da consulta WhatsApp Web, botões e atalhos já existentes.
7. **Área de trabalho principal com duas colunas** no desktop: fila de conferência (~60%) e painel do número selecionado (~40%). Em celular, empilhar. Não reproduzir uma lista de 512 itens sem paginação ou virtualização; preserve o sistema atual.
8. **Fila**: linha clara para número, vínculos, status técnico e confirmação humana. Evidenciar número selecionado. A lista deve continuar paginada e filtrável, sem quebrar seleções.
9. **Painel de detalhes**: número em destaque, situação técnica separada da humana, contatos vinculados, botão `Abrir no WhatsApp`, observação, botões `Tem WhatsApp` / `Não possui`. Preservar a validação de observação para `Não possui`, sincronização com Contatos e histórico. Sem gravar pelo navegador nem disparar mensagem.
10. Exibir notas explicativas pequenas: resultados do WhatsApp Web são indícios técnicos e dependem de confirmação humana, sem afirmar precisão absoluta. Manter observação de que telefones fixos podem ter WhatsApp Business.
11. Visual do Prospector: azul petróleo, verde-petróleo, branco, cinza claro; cards com borda suave de 12-16px, espaçamento consistente, tipografia legível, hierarquia clara. Sem mudanças no menu lateral / cabeçalho global.
12. Responsivo e acessível: teclado, focus visível, aria-live para status, leitura de status não dependente apenas de cor.

## Regras obrigatórias de segurança

- **NÃO mexer** em rotas, serviços de consulta, filas, credenciais, login, bibliotecas não oficiais, integrações HubSpot/Receita, migrations ou confirmação humana, salvo se identificar bug provocado especificamente pela refatoração, justificar e validar.
- **NÃO misturar** “Encontrado” (indício da consulta) com “Confirmado” (verificação manual).
- **NÃO alterar contagens** nem normalização, filtros, partição por sessão ou status existentes.
- **NÃO inventar callbacks**. Preservar rigorosamente `wire:click`, `wire:model`, `wire:poll`, `wire:key`, loading, eventos Alpine/Livewire, paginação e status de conexão originais.
- **NÃO introduzir N+1**, nem consultas em Blade; manter o fluxo existente.
- Não executar operações reais em massa apenas para testar.

## Entrega obrigatória

1. Código completo dos arquivos realmente alterados, sem truncar.
2. Script de instalação idempotente com backup, verificação de versão de arquivos, validação e rollback.
3. Testes automatizados existentes relevantes + novos testes de renderização/interface, sem atingir serviços reais.
4. `php artisan view:cache`, testes focalizados, `git diff --check` e avaliação de build Vite se CSS foi integrado no bundle.
5. `README.md` com diferenças e instruções para aplicar/reverter.
6. Pacote ZIP pronto para subir, usando o código original do projeto.

**Importante:** O objetivo é uma tela realmente reorganizada e funcional, não um print, não uma lista de sugestões e não um mock. Faça o trabalho de ponta a ponta no mesmo turno, com a estrutura atual como fonte da verdade.
