# Checklist de validação — Verificação WhatsApp V7

1. Acesse `/contatos/verificacao-whatsapp` em Chrome, desktop e celular.
2. A página deve exibir: resumo, andamento, duas contas, histórico, filtros, fila, detalhe e atalhos de navegação (os atalhos só aparecem se as seções forem detectadas).
3. Compare os números da tela com a versão anterior. Nenhum contador deve mudar **por causa do pacote**.
4. Confirme que os botões de **conferir próximos números** continuam acionando a lógica normal, com lote de tamanho selecionado.
5. Não envie lote sem autorização: para testar, prefira ambiente de homologação, conta de teste ou inspecione apenas o fluxo/handlers, sem disparar consulta real.
6. Valide o seletor dos números, os filtros, a paginação e os resultados recentes.
7. Confira o botão **Abrir no WhatsApp** e a marcação manual **Tem WhatsApp / Não possui**, inclusive validação de observação obrigatória para “Não possui”.
8. Verifique se a mesma alteração aparece nos contatos vinculados.
9. Saia da rota e confirme que as outras telas do Prospector não foram afetadas.
10. Pressione `Ctrl+Shift+R` e valide a versão móvel (< 640px), tablet e desktop.
11. Analise no navegador os erros do Console: nenhum erro novo de JavaScript, Livewire ou Alpine.
12. Em caso de comportamento inesperado, rode `reverter.sh` e compare.

## Comandos opcionais de desenvolvimento

```bash
cd ~/projetos/prospector-exportcontrol
./vendor/bin/sail artisan route:list --path=contatos
./vendor/bin/sail artisan view:clear
./vendor/bin/sail artisan test --filter=WhatsApp
# Só depois de validar e se fizer sentido:
git diff --check
git status --short
```

*Se a instalação encontrar mais de um layout, o script cancela. Isso evita instalar o asset na página errada.*
