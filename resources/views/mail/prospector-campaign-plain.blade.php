{{ $messageBody }}

@if ($unsubscribeUrl)
Para não receber novos e-mails comerciais da ExportControl, solicite o descadastro em:
{{ $unsubscribeUrl }}
@endif
@if ($isTest)

[MENSAGEM DE TESTE — NÃO ENVIADA AOS CONTATOS DA CAMPANHA]
@endif
