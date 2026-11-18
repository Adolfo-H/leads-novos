{{-- Retornos IMAP: cabeçalhos vinculados, SEM alterar métricas de resposta humana. --}}
@php
    $ecInboundAvailable = \Illuminate\Support\Facades\Schema::hasTable('email_campaign_inbound_notices');
    $ecInboundCount = $ecInboundAvailable
        ? \App\Services\Campaigns\CampaignInboundNoticeFilter::query()
            ->where('email_campaign_id', (int) $selected->id)->count()
        : 0;
    $ecInboundRows = $ecInboundCount > 0
        ? \App\Services\Campaigns\CampaignInboundNoticeFilter::query()
            ->where('email_campaign_id', (int) $selected->id)
            ->orderByDesc('received_at')->limit(100)->get()
        : collect();
    $ecInboundLabels = [
        'automatic' => 'Confirmação automática',
        'auto_reply' => 'Resposta automática',
        'absence' => 'Férias / ausência',
        'address_change' => 'Endereço alterado',
        'routing' => 'Redirecionamento',
        'reply_needs_review' => 'Possível resposta (revisar)',
    ];
    $ecInboundMatches = [
        'reference_exact' => 'Vínculo confirmado por identificador e remetente',
        'reference_mismatch' => 'Identificador encontrado; remetente diferente',
        'sender_only' => 'Possível vínculo pelo remetente; conferir no e-mail original',
    ];
@endphp
<section aria-label="Retornos automáticos e não confirmados" style="margin:22px 0 8px;padding:18px;border:1px solid #cbdfe1;border-radius:12px;background:#f8fbfa;color:#164352">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <h3 style="font-size:16px;font-weight:750;margin:0">Retornos automáticos e para revisão</h3>
        <span style="font-weight:750;color:#077e73">{{ number_format((int) $ecInboundCount, 0, ',', '.') }} retorno(s)</span>
    </div>
    <p style="font-size:12px;color:#547482;line-height:1.6;margin:8px 0 14px">Somente avisos úteis: férias, confirmações automáticas, mudanças de endereço e possíveis respostas para revisão. Não são respostas humanas. Falhas de entrega, endereços inválidos, caixa cheia e spam continuam na área de devoluções. Consulte o Outlook para confirmar as mensagens.</p>
    @if ($ecInboundRows->isEmpty())
        <p style="font-size:13px;margin:0;color:#597382">Nenhum retorno adicional vinculado até agora. Utilize a recuperação IMAP para mensagens antigas.</p>
    @else
        <div style="overflow-x:auto">
            <table class="ecm2-table" style="width:100%">
                <thead><tr><th>Tipo</th><th>Remetente</th><th>Assunto</th><th>Vínculo</th><th>Recebido</th></tr></thead>
                <tbody>
                @foreach ($ecInboundRows as $ecNotice)
                    <tr>
                        <td><strong>{{ $ecInboundLabels[$ecNotice->notice_type] ?? 'Retorno recebido' }}</strong>@if ($ecNotice->requires_review)<small style="display:block;color:#a35b00">Revisão necessária</small>@endif</td>
                        <td>{{ $ecNotice->from_email }}</td>
                        <td>{{ $ecNotice->subject ?: '(sem assunto)' }}</td>
                        <td><small>{{ $ecInboundMatches[$ecNotice->link_method] ?? 'Conferir' }}</small></td>
                        <td>{{ \App\Support\CampaignLocalTime::format($ecNotice->received_at, '—') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($ecInboundCount > 100)
            <p style="font-size:12px;color:#607e87">Exibindo os 100 mais recentes de {{ number_format((int) $ecInboundCount, 0, ',', '.') }} retornos.</p>
        @endif
    @endif
    <p style="font-size:12px;color:#6c7882;margin:12px 0 0">Atenção: avisos automáticos ainda não bloqueiam follow-ups nem atualizam o HubSpot. Revise endereços alterados e elegibilidade antes da próxima rodada.</p>
</section>

{{-- Somente quando existe uma UNICA campanha candidata; nunca inferir destinatario. --}}
@php
    $ecV21Unlinked = \Illuminate\Support\Facades\Schema::hasTable('email_campaign_unlinked_notices')
        ? \Illuminate\Support\Facades\DB::table('email_campaign_unlinked_notices')
            ->where('candidate_campaign_id', (int) $selected->id)
            ->orderByDesc('received_at')->limit(15)->get()
        : collect();
@endphp
@if ($ecV21Unlinked->isNotEmpty())
<section id="ec-v21-campaign-unlinked" style="margin:16px 0;padding:16px;border:1px solid #e0c694;border-radius:10px;background:#fffbf3">
    <h3 style="margin:0 0 7px;font-size:14px">E-mails alterados — associação não confirmada</h3>
    <p style="font-size:12px">Estes avisos citam exatamente o assunto da campanha, mas não confirmam o destinatário. Revise no Outlook antes de atualizar contatos ou programar novas rodadas.</p>
    <ul>
        @foreach ($ecV21Unlinked as $ecV21Notice)
            <li>{{ $ecV21Notice->from_email }} — {{ $ecV21Notice->subject }} ({{ \App\Support\CampaignLocalTime::format($ecV21Notice->received_at, '—') }})</li>
        @endforeach
    </ul>
</section>
@endif
