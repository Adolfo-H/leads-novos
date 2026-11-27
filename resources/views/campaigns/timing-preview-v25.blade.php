{{-- EC_CAMPAIGN_TIMING_V25: estimates only; no policy/queue writes. --}}
@php
    $ecV25Policy = app(\App\Services\Campaigns\CampaignSendPolicy::class)->effective();
    $ecV25Total = count($expected);
    $ecV25Hours = (int) ($state['hours'] ?? 0);
    if (! in_array($ecV25Hours, \App\Services\Campaigns\CampaignTimingPreview::HOURS, true)) {
        $ecV25Hours = 0;
    }
    $ecV25Estimates = [];
    foreach (\App\Services\Campaigns\CampaignTimingPreview::HOURS as $ecV25Duration) {
        $ecV25Estimate = \App\Services\Campaigns\CampaignTimingPreview::estimate($ecV25Total, $ecV25Duration, $ecV25Policy);
        $ecV25Estimates[$ecV25Duration] = array_merge($ecV25Estimate, ['label' => \App\Services\Campaigns\CampaignTimingPreview::label($ecV25Estimate['minutes']), 'mode' => $ecV25Duration === 0 ? 'Automático · sem limite individual adicional' : 'Cadência própria']);
    }
    $ecV25Current = $ecV25Estimates[$ecV25Hours];
@endphp
<div data-ec-v25-timing style="margin:16px 0;border:1px solid #bcded8;border-radius:13px;padding:18px;background:#f7fbfa;color:#153a43;line-height:1.5">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div><small style="font-size:11px;color:#087c70;font-weight:800;letter-spacing:.06em">PREVISÃO DE PROCESSAMENTO</small><h3 style="font-size:16px;margin:4px 0 0;font-weight:750">Tempo estimado após iniciar</h3></div>
        <span style="font-size:11px;color:#51727b;border:1px solid #d2e5e2;border-radius:6px;padding:5px 8px;background:#fff">{{ number_format($ecV25Total, 0, ',', '.') }} destinatário(s)</span>
    </div>
    <div aria-live="polite" style="margin:12px 0 8px"><strong data-ec-v25-duration style="font-size:30px;line-height:1.2;font-weight:800;color:#087c70">{{ $ecV25Current['label'] }}</strong></div>
    <p style="font-size:12px;color:#51727b;margin:0 0 16px">Estimativa teórica com cotas disponíveis, fila livre e agendador ativo. Não é um horário garantido de conclusão ou entrega.</p>
    <div style="font-size:12px;font-weight:750;margin:0 0 8px">Limites globais vigentes · soma das contas</div>
    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:16px">
        @foreach (['tick' => 'Por minuto', 'hour' => 'Por hora', 'day' => 'Em 24 horas'] as $ecV25Unit => $ecV25Label)
            <div style="background:#fff;border:1px solid #dce8e6;border-radius:9px;padding:10px;min-width:0"><small style="display:block;font-size:11px;color:#51727b">{{ $ecV25Label }}</small><strong style="display:block;font-size:20px;overflow-wrap:anywhere">{{ number_format($ecV25Policy[$ecV25Unit], 0, ',', '.') }}</strong></div>
        @endforeach
    </div>
    <div style="border-top:1px solid #dce8e6;padding-top:12px;font-size:12px;color:#34575d">
        <strong data-ec-v26-mode style="display:block;margin-bottom:5px">{{ $ecV25Current['mode'] }}</strong>
        Capacidade efetiva de referência: até <strong data-ec-v25-hour>{{ number_format($ecV25Current['hour'], 0, ',', '.') }}</strong> por hora e <strong data-ec-v25-tick>{{ number_format($ecV25Current['tick'], 0, ',', '.') }}</strong> por minuto.
        <p style="margin:6px 0 0;color:#51727b">O modo automático não altera as cotas. A previsão considera o próximo ciclo do agendador, com cotas livres; outras campanhas, cotas já usadas, fila, indisponibilidade ou bloqueios SMTP/IMAP podem adiar ou interromper o processamento.</p>
    </div>
    @if (! config('campaign_mail.enabled') || ! config('campaign_mail.live_enabled') || \App\Services\Campaigns\DualCampaignSenders::state()['blocked'])
        <p role="status" style="margin:12px 0 0;color:#934a23;font-size:12px;font-weight:700">Envio desabilitado ou bloqueado neste momento. A previsão é apenas informativa.</p>
    @endif
    @if ($step === 'ritmo')
        <script>
        (() => {
            const panel = document.currentScript.closest('[data-ec-v25-timing]');
            const select = panel?.closest('form')?.querySelector('select[name=hours]');
            if (!select) return;
            const estimates = @json($ecV25Estimates);
            const update = () => {
                const next = estimates[select.value];
                if (!next) return;
                panel.querySelector('[data-ec-v25-duration]').textContent = next.label;
                panel.querySelector('[data-ec-v26-mode]').textContent = next.mode;
                panel.querySelector('[data-ec-v25-hour]').textContent = next.hour.toLocaleString('pt-BR');
                panel.querySelector('[data-ec-v25-tick]').textContent = next.tick.toLocaleString('pt-BR');
            };
            select.addEventListener('change', update);
            update();
        })();
        </script>
    @endif
</div>
