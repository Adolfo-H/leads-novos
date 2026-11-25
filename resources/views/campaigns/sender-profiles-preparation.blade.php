{{-- EC_CAMPAIGN_SENDER_PROFILES_V2 --}}
@if (auth()->user()?->isCommercialManager())
    @php
        $senderProfiles = \App\Services\Campaigns\CampaignSenderProfiles::summary((array) config('campaign_sender_profiles', []));
        $dualState = \App\Services\Campaigns\DualCampaignSenders::state();
        $dualReady = \App\Services\Campaigns\DualCampaignSenders::ready();
    @endphp
    <section aria-label="Contas comerciais de envio" style="border:1px solid #d7e7e3;border-radius:12px;background:white;padding:20px;margin:18px 0">
        <header class="ec22-card-head" style="margin-bottom:14px">
            <div><h2>Contas comerciais de envio</h2><p>Remetente fixado por destinatário na aprovação da campanha. Cotas globais compartilhadas.</p></div>
            <span class="ec22-state {{ $dualState['enabled'] && ! $dualState['blocked'] && $dualReady ? 'is-live' : 'is-safe' }}">
                {{ $dualState['blocked'] ? 'Bloqueio SMTP — revisão obrigatória' : ($dualState['enabled'] ? ($dualReady ? 'Contas dedicadas autorizadas' : 'Aguardando sincronização') : 'Aguardando autorização do provedor') }}
            </span>
        </header>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">
            @foreach ($senderProfiles as $index => $profile)
                @php($profileKey = \App\Services\Campaigns\DualCampaignSenders::KEYS[$index])
                <article class="ec22-card" style="min-width:0;overflow-wrap:anywhere">
                    <p style="color:#527184;font-size:12px;margin-bottom:5px">{{ $profile['label'] }}</p>
                    <h3 style="font-size:16px;color:#12374a;margin-bottom:12px">{{ $profile['address'] }}</h3>
                    <p>SMTP: <strong>{{ $profile['smtp_ready'] ? 'Configuração preenchida' : 'Configuração pendente' }}</strong></p>
                    <p>IMAP: <strong>{{ \App\Services\Campaigns\DualCampaignSenders::inboxReady($profileKey) ? 'Sincronização recente' : 'Aguardando sincronização' }}</strong></p>
                    @if ($profile['duplicate'])<p role="alert">Endereços duplicados. Confira o cadastro.</p>@endif
                    @if ($dualState['enabled'] && ! $dualState['blocked'] && $dualReady)
                        <p style="color:#08786b;font-size:12px;margin-top:10px">Disponível para campanhas novas autorizadas.</p>
                    @else
                        <p style="color:#527184;font-size:12px;margin-top:10px">Não disponível para novos envios dedicados.</p>
                    @endif
                </article>
            @endforeach
        </div>
        <div class="ec22-info-note" style="margin-top:14px">
            @if ($dualState['enabled'])
                <strong>Modo:</strong> duas contas dedicadas; cada destinatário mantém sua caixa e o Reply-To correspondente.<br>
                Campanhas antigas não são transferidas. Bloqueio SMTP interrompe as novas tentativas das duas caixas. Limites abaixo valem para a soma dos envios.
            @else
                <strong>Remetente legado:</strong> {{ config('campaign_mail.from_address') ?: 'Não informado' }}<br>
                O cadastro das caixas não altera o remetente até autorização explícita pelo administrador.
            @endif
        </div>
    </section>
@endif
