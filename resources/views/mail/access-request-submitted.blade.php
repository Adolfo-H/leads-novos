<!doctype html><html lang="pt-BR"><body style="font-family:Arial,sans-serif;color:#0d2942">
<h2>ExportControl Prospector — novo pedido de acesso</h2>
<p>Há uma solicitação para sua análise. Nenhuma conta foi criada automaticamente.</p>
<p><strong>Nome:</strong> {{ $accessRequest->name }}</p>
<p><strong>E-mail:</strong> {{ $accessRequest->email }}</p>
<p><strong>Empresa:</strong> {{ $accessRequest->company }}</p>
<p><strong>Telefone:</strong> {{ $accessRequest->phone ?: 'Não informado' }}</p>
<p><strong>Cargo:</strong> {{ $accessRequest->job_title ?: 'Não informado' }}</p>
<p><a href="{{ $managementUrl }}">Analisar solicitação no Prospector</a></p>
<p>Esta ação exige login na conta de administrador e autorização de gestor.</p>
</body></html>
