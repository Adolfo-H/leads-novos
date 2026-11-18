{{-- Tela geral de respostas: somente retornos comerciais úteis, sem DSN ou spam. --}}
@php
    $noticeLabels = [
        'absence' => 'Férias / ausência',
        'address_change' => 'E-mail alterado',
        'routing' => 'Redirecionamento',
        'automatic' => 'Confirmação automática',
        'auto_reply' => 'Resposta automática',
        'reply_needs_review' => 'Possível resposta — revisar',
    ];
    $campaignNames = \Illuminate\Support\Facades\DB::table('email_campaigns')
        ->whereIn('id', $this->inboundNotices->getCollection()->pluck('email_campaign_id')->unique()->all())
        ->pluck('name', 'id');
    $noticeLinks = [
        'reference_exact' => 'Correspondência confirmada',
        'reference_mismatch' => 'Remetente diferente — revisar',
        'sender_only' => 'Associação provável — revisar',
    ];
@endphp
<section class="ec21-panel" aria-label="Retornos úteis" style="padding:20px;margin-top:14px;background:#fff;border:1px solid #cfdfe1;border-radius:12px">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:10px">
        <div>
            <h2 style="font-size:18px;font-weight:750;margin:0;color:#164352">Retornos automáticos úteis</h2>
            <p style="font-size:12px;line-height:1.5;color:#5b7785;margin:6px 0">Avisos de férias, recebimento, alteração de e-mail e retornos para revisão, de todas as campanhas.</p>
        </div>
        <strong style="font-size:16px;color:#087f74">{{ number_format($this->inboundNotices->total(), 0, ',', '.') }} retorno(s)</strong>
    </div>
    <p style="font-size:12px;line-height:1.5;color:#607e87;margin:0 0 16px">Não inclui endereço inválido, caixa cheia, rejeição pelo servidor, spam ou outras falhas de entrega. Esses assuntos continuam em Devoluções. Os avisos não contam como respostas humanas.</p>
    @if ($this->inboundNotices->isEmpty())
        <p style="padding:18px;background:#f6faf9;border-radius:8px;font-size:13px;color:#637e88">Nenhum aviso útil identificado{{ $search !== '' ? ' para esta pesquisa' : '' }}.</p>
    @else
        <div style="overflow-x:auto">
            <table class="ecm2-table" style="width:100%">
                <thead><tr><th>Tipo</th><th>Remetente</th><th>Assunto</th><th>Campanha</th><th>Recebido</th></tr></thead>
                <tbody>
                    @foreach ($this->inboundNotices as $notice)
                        <tr wire:key="ec-inbound-global-{{ $notice->id }}">
                            <td><strong>{{ $noticeLabels[$notice->notice_type] ?? 'Aviso para revisão' }}</strong>
                                @if ($notice->requires_review)<small style="display:block;color:#a26212">Confirmar no Outlook</small>@endif
                            </td>
                            <td>{{ $notice->from_email }}</td>
                            <td>{{ $notice->subject ?: '(sem assunto)' }}<small style="display:block;color:#66818b">{{ $noticeLinks[$notice->link_method] ?? 'Conferir vínculo' }}</small></td>
                            <td><a href="{{ route('campaigns.index', ['campanha' => $notice->email_campaign_id, 'aba' => 'replies']) }}" style="color:#08766c;text-decoration:underline">{{ $campaignNames[(int) $notice->email_campaign_id] ?? ('Campanha #'.$notice->email_campaign_id) }}</a></td>
                            <td>{{ \App\Support\CampaignLocalTime::format($notice->received_at, '—') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top:14px">{{ $this->inboundNotices->links() }}</div>
    @endif
</section>

{{-- EC_INBOUND_V21: casos com assunto de campanha, mas sem destinatario confirmado. --}}
@php
    $ecV21CanRead = \Illuminate\Support\Facades\Schema::hasTable('email_campaign_unlinked_notices');
    $ecV21Term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($search), 0, 100));
    $ecV21Unlinked = $ecV21CanRead
        ? \Illuminate\Support\Facades\DB::table('email_campaign_unlinked_notices')
            ->when($ecV21Term !== '', static function ($query) use ($ecV21Term): void {
                $query->where(static function ($filter) use ($ecV21Term): void {
                    $filter->where('from_email', 'like', '%'.$ecV21Term.'%')
                        ->orWhere('subject', 'like', '%'.$ecV21Term.'%');
                });
            })->orderByDesc('received_at')->limit(40)->get()
        : collect();
@endphp
@if ($ecV21Unlinked->isNotEmpty())
<section class="ec21-panel" aria-label="Avisos sem vinculo confirmado" id="ec-v21-unlinked" style="padding:20px;margin-top:14px;border:1px solid #e3c389;border-radius:12px;background:#fffaf3">
    <h3 style="margin:0 0 8px;font-size:16px;color:#65491b">Avisos de e-mail alterado — vínculo para conferir</h3>
    <p style="font-size:12px;color:#765d37">O assunto indica uma resposta à prospecção, mas não confirmou qual destinatário respondeu. Não altera o contato nem os próximos envios. Confira no Outlook.</p>
    <div style="overflow-x:auto"><table class="ecm2-table" style="width:100%">
        <thead><tr><th>Remetente</th><th>Assunto</th><th>Campanha provável</th><th>Data</th></tr></thead>
        <tbody>
        @foreach ($ecV21Unlinked as $ecV21Notice)
            <tr>
                <td>{{ $ecV21Notice->from_email }}</td>
                <td>{{ $ecV21Notice->subject }}</td>
                <td>@if ($ecV21Notice->candidate_campaign_id !== null)
                    <a href="{{ route('campaigns.index', ['campanha' => $ecV21Notice->candidate_campaign_id, 'aba' => 'replies']) }}">Campanha #{{ $ecV21Notice->candidate_campaign_id }} (revisar)</a>
                    @else Campanha não confirmada @endif</td>
                <td>{{ \App\Support\CampaignLocalTime::format($ecV21Notice->received_at, '—') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</section>
@endif
