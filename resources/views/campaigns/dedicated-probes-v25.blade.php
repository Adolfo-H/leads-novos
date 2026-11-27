{{-- EC_CAMPAIGN_PROBES_UI_V25: presentation only; existing controller/service validates every POST. --}}
@php
    $ecV25ProbeState = $ecProbeState;
    // Fetch only the latest diagnostic per account, including historical IDs.
    $ecV26ProbeRows = [];
    foreach (\App\Services\Campaigns\DualCampaignSenders::KEYS as $ecV26Key) {
        $ecV26Base = 'ec-dedicated-test-'.$selected->id.'-'.$ecV26Key;
        $ecV26ProbeRows[$ecV26Key] = \App\Models\EmailCampaignTestMessage::query()
            ->where('email_campaign_id', $selected->id)
            ->where(static function ($query) use ($ecV26Base): void {
                $query->where('message_id', $ecV26Base.'@exportcontrol.com.br')
                    ->orWhere('message_id', 'like', $ecV26Base.'-%@exportcontrol.com.br');
            })->orderByDesc('id')->first();
    }
    $ecV26ProbeBusy = \App\Services\Campaigns\DedicatedCampaignProbe::inProgress();
    $ecV25ProbeReady = ! $ecV25ProbeState['blocked'] && ! $ecV26ProbeBusy && $selected->status === 'draft'
        && config('campaign_mail.enabled') && \App\Services\Campaigns\DualCampaignSenders::ready()
        && filter_var((string) config('campaign_mail.test_to'), FILTER_VALIDATE_EMAIL) !== false;
@endphp
<div data-ec-v25-probes style="display:grid;gap:13px;line-height:1.45">
    <div style="border:1px solid #d9e5e8;border-radius:10px;padding:13px;background:#f7fbfa">
        <strong style="display:block;font-size:13px;color:#123b40">Diagnóstico interno</strong>
        <p style="margin:4px 0 0;font-size:12px;color:#51727b">Destino autorizado: <strong style="overflow-wrap:anywhere">{{ config('campaign_mail.test_to') ?: 'Não configurado' }}</strong>. Nenhum destinatário da campanha é utilizado.</p>
    </div>
    @if ($selected->status === 'draft' && ! $ecV25ProbeState['blocked'])
        <form method="POST" action="{{ route('campaigns.email-test', ['campaign' => $selected->id], false) }}" style="display:grid;gap:12px;margin:0">
            @csrf
            <input type="hidden" name="aba" value="{{ $detailTab }}">
            <input type="hidden" name="dedicated_test_request" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <label style="display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#173c43"><input type="checkbox" name="dedicated_test_confirm" value="1" required style="accent-color:#087c70;margin-top:2px"> Confirmo que o destino é minha caixa de teste autorizada.</label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:10px">
                @foreach (\App\Services\Campaigns\DualCampaignSenders::KEYS as $ecProbeKey)
                    @php
                        $ecV25Probe = $ecV26ProbeRows[$ecProbeKey] ?? null;
                        $ecV25ProbeLabel = $ecV25Probe === null ? 'Ainda não testado' : ($ecV25Probe->replied_at !== null ? 'Resposta registrada' : match ($ecV25Probe->status) {
                            'smtp_accepted' => 'Aceito pelo SMTP',
                            'initiated' => 'Tentativa iniciada',
                            'uncertain' => 'Resultado incerto · revisar',
                            default => 'Tentativa registrada',
                        });
                    @endphp
                    <article style="border:1px solid #dce7e9;background:white;border-radius:11px;padding:14px;min-width:0;display:grid;gap:8px">
                        <span aria-hidden="true" style="display:grid;place-items:center;width:34px;height:34px;border-radius:9px;background:#e6f5f1;color:#087c70"><svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6"/></svg></span>
                        <strong style="color:#123b40;font-size:14px">{{ ucfirst($ecProbeKey) }}</strong>
                        <small style="color:#51727b;font-size:12px;overflow-wrap:anywhere">{{ $ecProbeKey }}@exportcontrol.com.br</small>
                        <small style="color:#34575d;font-size:11px">Último teste: {{ $ecV25ProbeLabel }}</small>
                        <button type="submit" name="sender_profile" value="{{ $ecProbeKey }}" class="ecm2-btn ecm2-btn-muted" style="width:100%;margin-top:4px" @disabled(! $ecV25ProbeReady || ($ecV25Probe !== null && in_array($ecV25Probe->status, ['initiated', 'uncertain'], true)))>{{ $ecV25Probe === null ? 'Enviar teste por' : 'Novo teste por' }} {{ ucfirst($ecProbeKey) }}</button>
                    </article>
                @endforeach
            </div>
        </form>
        <small style="font-size:11px;color:#51727b">Novos testes manuais são permitidos na mesma campanha, dentro das cotas globais. Cada solicitação é única e fica registrada. Não há repetição automática nem reenvio incerto. O resultado SMTP não comprova entrega.</small>
    @else
        <p style="margin:0;font-size:12px;color:#934a23">Diagnóstico indisponível: use um rascunho e confira o bloqueio SMTP.</p>
    @endif
</div>
