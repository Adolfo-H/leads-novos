# ExportControl Prospector — Central de Verificação WhatsApp — UI V7

**Pacote visual instalável e reversível para Laravel/Livewire.**

## O que está incluído

- `instalar.sh`: detecta o layout Blade principal, cria backup e inclui CSS/JavaScript somente de apresentação.
- `reverter.sh`: restaura o layout e os assets anteriores.
- `public/assets/whatsapp-verificacao-v7.css`: estilos responsivos e hierarquia visual.
- `public/assets/whatsapp-verificacao-v7.js`: identifica os blocos existentes pelo texto, aplica estilos e cria atalhos para navegar entre as seções (sem mover componentes Livewire).
- `preview.html`: prévia visual estática com números mostrados nas capturas de tela, demonstra a organização pretendida. **Não é o backend real**.
- `docs/VALIDACAO.md`: roteiro prático de testes.
- `docs/PROMPT_CODEx.md`: especificação para a segunda etapa, um redesenho nativo completo no código da tela, caso você deseje refatorar componentes Blade/Livewire de verdade.

## Instalação

1. Extraia o ZIP em qualquer diretório da máquina em que está o projeto.
2. No terminal, execute:

```bash
cd ~/projetos/prospector-exportcontrol
bash /caminho/extraido/exportcontrol_whatsapp_ui_v7/instalar.sh "$PWD"
```

3. Abra a rota `/contatos/verificacao-whatsapp` e pressione `Ctrl+Shift+R`.
4. Se necessário, limpe o cache de views:

```bash
./vendor/bin/sail artisan view:clear
```

**Não é necessário executar migrations, alterar controllers, criar tabelas ou atualizar os serviços de WhatsApp.**

### Reverter

```bash
cd ~/projetos/prospector-exportcontrol
bash /caminho/extraido/exportcontrol_whatsapp_ui_v7/reverter.sh "$PWD"
```

### Segurança

- Não modifica filas, IDs pares/ímpares, lógica de consulta, endpoint, número de lote, filtros, confirmações ou dados.
- Não envia mensagens nem automatiza consultas.
- Não muda o significado de “Encontrado”: indício técnico **não é** confirmação humana.
- Não mexe nos registros do HubSpot ou Receita.
- As melhorias são *progressive enhancement*. Caso algum seletor não encontre o componente, ele o deixa como está.
- O instalador falha sem tocar no layout se não puder identificar com segurança o arquivo que contém `</head>`.
- O script cria cópia dos arquivos afetados em `.ecwa-ui-backups/`.

### Limitação importante

O código da view não foi enviado, apenas capturas. Este pacote melhora o **visual existente sem refatorar sua estrutura**. Portanto, não é possível prometer que a posição de todos os blocos ficará exatamente como a prévia HTML sem acesso à view Blade/Livewire. Para obter **o redesenho integral e fiel**, o `docs/PROMPT_CODEx.md` traz a instrução completa para implementar diretamente sobre os arquivos reais, com testes e rollback.

**Versão:** UI V7.0 · 04/11/2026
