# PACOTE 13 — Integridade de campanhas, sequências e cotas

## Pontos do código existente

- `app/Services/Campaigns/CampaignManager.php::saveDraft`: **ao editar campanha existente, atualmente executa `$campaign->recipients()->delete()`**, perdendo IDs e vínculos de respostas/sequências.
- `app/Services/Campaigns/CampaignDispatcher.php::tick`: reserva destinatários sob lock, mas cotas por `reserved_at` podem expirar antes de a tentativa SMTP começar.
- `app/Jobs/SendEmailCampaignRecipient.php::handle`: valida token da reserva, flags, opt-out, reply e janela; ao marcar `sending`, precisa garantir cota atômica **do momento da tentativa**.
- `app/Services/Campaigns/CampaignSequenceGuard.php`, `CampaignInbox.php`, `CampaignReplyReader.php`, `CampaignUnsubscribeLink.php`, `CampaignSendPolicy.php`, `CampaignSegmentation.php`, `CampaignProductionReadiness.php`.
- Modelos `EmailCampaign`, `EmailCampaignRecipient`, `EmailCampaignReply`, `EmailCampaignSuppression` e migrations de campanhas. NÃO alterar histórico de envios já realizados.

## Trabalho real de código

1. **Edição de rascunhos sem apagar destinatários**: substituir `recipients()->delete()` por reconciliação com correspondência estável (`campaign_id + source_key + normalized_email`, conforme regra vigente). Para registro já existente, preservar `id`, `message_id`, vínculos de reply/DSN, histórico, `sent_at`, `attempted_at`, `replied_at`, status e tokens quando aplicável. Não reclassificar `sent`, `sending`, `uncertain`, `replied` para `pending` por edição. Remoção de destinatário com vínculo histórico exige regra explícita (desativar/excluir de novas sequências, sem apagar histórico). Edite somente `draft` sob lock no banco, sem corrida com `start()`.
2. **Follow-up**: `CampaignSequenceGuard` deve consultar a mensagem original pelo ID persistente, não apenas por e-mail quando houver ambiguidade. Resposta/opt-out/bounce suprime toda sequência relevante, mesmo após editar assunto/corpo do rascunho. O projeto usa `PrepareCampaignFollowupDrafts.php`; respeitar como os relacionamentos são gravados.
3. **Cota na tentativa SMTP**: a reserva é uma etapa preliminar, não prova de envio. Reavaliar cota por hora/dia sob trava global *no mesmo commit transacional que altera `reserved` -> `sending` e grava `attempted_at`*. Contar tentativas já iniciadas de maneira confiável (distinguir `reserved_at` e `attempted_at`), inclusive atrasos de fila >1h. Não reusar token e não reemitir tentativa `uncertain`. Evitar deadlock com ordem de locks fixa (`gate`/`campaign`/`recipient` documentada e consistente entre Dispatcher, Manager e Job).
4. Quando não houver cota, retornar a espera/pendência sem perder vínculo; não marcar `sending`. Tratar concorrência de dois workers e dois ticks; cota nunca ultrapassa limites no início de tentativas. Timeout após aceite SMTP fica `uncertain`, sem retry automático.
5. **Autorização e descadastro**: garantir manager-only, URL HTTPS pública, validade de assinatura/token, `opt-out` em lista global consultado imediatamente antes do envio. Não habilitar automaticamente `CAMPAIGN_MAIL_ENABLED`, `CAMPAIGN_MAIL_LIVE_ENABLED`, `CAMPAIGN_PILOT_ENABLED` ou `CAMPAIGN_REPLY_SEND_ENABLED` (todos continuam desligados por padrão). Não aumentar cotas.
6. **IMAP**: `tools/email/imap_fetch.py` deve ter fallback para charset desconhecido e isolamento de erro por mensagem. Mensagem inválida não pode bloquear as mensagens seguintes; preservar cursor apenas conforme confirmação e evitar retrabalho infinito sem mascarar falha de conexão. Não revelar e-mails reais nos testes.
7. **Segmentação**: alinhar a exclusão de clientes para contatos manuais ligados a empresa cliente com política central de cliente/raiz CNPJ. Respeitar apenas vínculos fiscais seguros; canal cadastral não é uma pessoa real. Filtrar elegibilidade antes do limite/continuar varrendo por cursor para não retornar "zero elegível" falso.
8. **Simulação**: criar comando de auditoria/diagnóstico de fila e orçamento de envios que não agende nenhuma campanha nem faça SMTP. Relatar tentativas, reservas antigas, incertos, respostas e supressões somente por totais.

## Cenários de testes (obrigatórios antes de implementação final)

- Editar assunto de follow-up mantendo `recipient_id` e sequência; resposta à primeira mensagem bloqueia envio subsequente.
- Editar destinatários sem perder `replied_at`, opt-outs, IDs e vínculos históricos; não editar campanha em execução.
- 25 reservas com mais de uma hora + 25 novas: não ultrapassar cota horária na transição para `sending`.
- Worker duplicado e jobs fora de ordem: um único início de SMTP por destinatário e token; exceção SMTP após aceite -> `uncertain` não reenvia.
- `Mail::fake()` e `Http::fake()` obrigatórios; contagem de e-mails reais enviados: ZERO.
- Descadastro, bounce, resposta humana e `CampaignSequenceGuard` vencem preferência de audiência.
- Charset desconhecido no IMAP seguido de mensagem válida; segunda mensagem processada; credenciais fake.
- `tests/Feature/Campaigns/` existentes devem passar, incluindo V8/V9 de prontidão.

## Aceite

**NÃO ativar ou liberar campanhas comerciais.** Só é aceitável um código testado com `Mail::fake`, flags de produção desligadas e auditoria de retenção de dados. Se for necessário alterar schema para contadores/cotas, propor migration aditiva sem executá-la no ambiente real e explicar impacto da implantação.
