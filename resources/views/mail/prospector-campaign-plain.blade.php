{{ $messageBody }}

@include('mail.campaign-institutional-signature-text')

@if ($unsubscribeUrl)
Se não desejar novos e-mails comerciais da ExportControl, solicite o descadastro:
{{ $unsubscribeUrl }}
@endif
@if ($isTest)
[MENSAGEM DE TESTE — NÃO ENVIADA AOS CONTATOS DA CAMPANHA]
@endif
