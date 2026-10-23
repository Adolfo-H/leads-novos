<x-layouts::app :title="'Solicitações de acesso'">
<section class="ec24-management" aria-label="Administração de solicitações">
    <div class="ec24-management-header">
        <div><small>ADMINISTRAÇÃO / ACESSO</small><h1>Solicitações de acesso</h1><p>Aprove ou recuse pedidos. Nenhum cadastro é liberado sem sua autorização.</p></div>
        <a class="ec24-outline" href="{{ route('dashboard') }}">← Voltar ao Dashboard</a>
    </div>
    @if(session('access_success'))<div class="ec24-notice ec24-notice-success" role="status">{{ session('access_success') }}</div>@endif
    @if(session('access_error'))<div class="ec24-notice ec24-notice-error" role="alert">{{ session('access_error') }}</div>@endif
    <nav class="ec24-admin-filters" aria-label="Filtrar solicitações">
        @foreach(['all' => 'Todas', 'pending' => 'Pendentes', 'approved' => 'Aprovadas', 'rejected' => 'Recusadas', 'activated' => 'Ativadas'] as $key => $name)
            <a class="{{ $status === $key ? 'active' : '' }}" href="{{ route('access.requests.index', ['status' => $key]) }}">{{ $name }} <span>{{ $key === 'all' ? array_sum($counts->toArray()) : ($counts[$key] ?? 0) }}</span></a>
        @endforeach
    </nav>
    <div class="ec24-admin-table-wrap">
        <table class="ec24-admin-table">
            <caption class="sr-only">Pedidos de acesso ao ExportControl Prospector</caption>
            <thead><tr><th>Pessoa / e-mail</th><th>Empresa e cargo</th><th>Situação</th><th>Recebida em</th><th>Ações</th></tr></thead>
            <tbody>
                @forelse($records as $item)
                    <tr>
                        <td><strong>{{ $item->name }}</strong><small>{{ $item->email }}</small><small>{{ $item->phone }}</small></td>
                        <td><strong>{{ $item->company }}</strong><small>{{ $item->job_title ?: 'Cargo não informado' }}</small></td>
                        <td><span class="ec24-state ec24-state-{{ $item->status }}">{{ ['pending'=>'Pendente','approved'=>'Aprovada','rejected'=>'Recusada','activated'=>'Ativada'][$item->status] ?? $item->status }}</span>
                            @if($item->status === 'pending')
                                <small>Notificação: {{ ['accepted_by_mailer'=>'aceita pelo transporte (verifique a caixa)','failed'=>'falha no SMTP','local_only'=>'capturada localmente (sem entrega externa)','pending'=>'ainda não enviada'][$item->notification_status] ?? 'status desconhecido' }}</small>
                            @endif
                            @if($item->status === 'approved')<small>Convite: {{ ['accepted_by_mailer'=>'aceito pelo mailer','failed'=>'falha no envio','pending'=>'aguardando envio'][$item->invitation_status] ?? 'não enviado' }}</small>@endif
                        </td>
                        <td>{{ $item->created_at?->format('d/m/Y H:i') }}</td>
                        <td>
                            @if($item->status === 'pending')
                                <div class="ec24-action-row">
                                    <form method="POST" action="{{ route('access.requests.approve', $item) }}" onsubmit="return confirm('Aprovar este acesso e enviar convite individual?')">@csrf<button class="ec24-approve" type="submit">Aprovar</button></form>
                                    <form method="POST" action="{{ route('access.requests.reject', $item) }}" onsubmit="return confirm('Recusar esta solicitação?')">@csrf<button class="ec24-reject" type="submit">Recusar</button></form>
                                    <form method="POST" action="{{ route('access.requests.resend-notification', $item) }}" onsubmit="return confirm('Reenviar aviso ao administrador?')">@csrf<button class="ec24-retry" type="submit">Reenviar aviso</button></form>
                                </div>
                            @elseif($item->status === 'approved')
                                <form method="POST" action="{{ route('access.requests.resend', $item) }}" onsubmit="return confirm('Reenviar convite? Isso cancelará o link anterior.')">@csrf<button class="ec24-retry" type="submit">Reenviar convite</button></form>
                                <form method="POST" action="{{ route('access.requests.revoke', $item) }}" onsubmit="return confirm('Revogar acesso antes da ativação?')">@csrf<button class="ec24-reject" type="submit">Revogar</button></form>
                            @else
                                <span class="ec24-muted">Nenhuma ação pendente</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="ec24-admin-empty">Nenhuma solicitação nesta categoria.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="ec24-admin-pager">{{ $records->links() }}</div>
    <p class="ec24-admin-legal">Convites são de uso único, expiram automaticamente e nunca conferem perfil de gestor. Envio aceito pelo mailer não garante entrega ao destinatário.</p>
</section>
</x-layouts::app>
