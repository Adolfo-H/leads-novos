{{-- Acompanhamento automático — usa os registros reais de campanha/rodada. --}}
@php
    $ecAutoRoot = (int) ($selected->followup_root_campaign_id ?: $selected->id);
    $ecAutoPlans = \Illuminate\Support\Facades\DB::table('email_campaign_sequences')
        ->where('email_campaign_id', $ecAutoRoot)->orderByDesc('id')->limit(8)->get();
    $ecAutoRounds = \App\Models\EmailCampaign::query()
        ->where(function ($q) use ($ecAutoRoot) {
            $q->whereKey($ecAutoRoot)->orWhere('followup_root_campaign_id', $ecAutoRoot);
        })
        ->withCount(['recipients as ec_auto_accepted_count' => fn ($q) => $q->where('status', 'sent')])
        ->orderBy('followup_round')->orderBy('id')->limit(25)->get();
    $ecAutoNames = ['draft' => 'Rascunho', 'running' => 'Enviando', 'paused' => 'Pausada',
        'completed' => 'Concluída', 'canceled' => 'Cancelada'];
@endphp
<section class="ec-auto-panel" aria-label="Rodadas e continuidade comercial">
    <header class="ec-auto-header">
        <div><h3>Continuidade comercial</h3><p>Rodadas e mensagens enviadas após a primeira campanha.</p></div>
        <span class="ec-auto-label">Fluxo #{{ $ecAutoRoot }}</span>
    </header>
    <ol class="ec-auto-timeline" aria-label="Histórico de rodadas">
        @foreach ($ecAutoRounds as $round)
            <li class="{{ $round->id === $selected->id ? 'is-selected' : '' }}">
                <div class="ec-auto-step">{{ (int) $round->followup_round }}</div>
                <div class="ec-auto-step-text"><strong>Rodada {{ (int) $round->followup_round }} — {{ $round->name }}</strong>
                    <small>{{ $ecAutoNames[$round->status] ?? $round->status }} · {{ (int) $round->ec_auto_accepted_count }} aceitos SMTP
                    @if ($round->started_at) · Início: {{ $round->started_at->format('d/m/Y H:i') }} @endif</small>
                </div>
                @if ($round->id !== $selected->id)
                    <button type="button" wire:click="openCampaign({{ $round->id }})" class="ec-auto-link">Ver rodada</button>
                @else
                    <span class="ec-auto-current">Atual</span>
                @endif
            </li>
        @endforeach
    </ol>

    @forelse ($ecAutoPlans as $plan)
        @php
            $ecLast = $ecAutoRounds->firstWhere('id', (int) ($plan->last_campaign_id ?: $plan->email_campaign_id));
            $ecDue = ($ecLast && $ecLast->status === 'completed' && $ecLast->completed_at)
                ? $ecLast->completed_at->copy()->addDays((int) $plan->delay_days)->format('d/m/Y') : null;
        @endphp
        <div class="ec-auto-plan">
            <div><strong>{{ $plan->name }}</strong>
                <small>{{ (int) $plan->delay_days }} dia(s) entre rodadas · {{ (int) ($plan->sent_rounds ?? 0) }}/{{ (int) ($plan->max_rounds ?? 1) }} follow-up(s) iniciado(s)
                    @if ($plan->status === 'active') · {{ $ecDue ? 'Próxima previsão: '.$ecDue : 'Aguardando concluir rodada anterior' }} @endif
                </small>
            </div>
            <span class="ec-auto-status">{{ match ($plan->status) { 'active' => 'Automático ativo', 'paused' => 'Pausado', 'finished' => 'Finalizado', 'planned' => 'Planejado', 'prepared' => 'Rascunho criado', default => $plan->status } }}</span>
            @if ($canManageCampaigns && (bool) ($plan->automatic ?? false) && in_array($plan->status, ['active', 'paused'], true))
                <form method="POST" action="{{ route('campaigns.intelligence.sequence.pause', ['sequence' => $plan->id]) }}">
                    @csrf
                    <input type="hidden" name="pause" value="{{ $plan->status === 'active' ? '1' : '0' }}">
                    <button type="submit" class="ec-auto-link">{{ $plan->status === 'active' ? 'Pausar próximas rodadas' : 'Retomar' }}</button>
                </form>
            @endif
        </div>
    @empty
        <p class="ec-auto-empty">Nenhuma continuidade programada para este fluxo.</p>
    @endforelse

    @if ($canManageCampaigns && $selected->id === $ecAutoRoot && $selected->status === 'completed' && $sentCount > 0)
        <details class="ec-auto-compose">
            <summary>Configurar envios automáticos após a campanha</summary>
            <form method="POST" action="{{ route('campaigns.intelligence.sequence') }}">
                @csrf
                <input type="hidden" name="email_campaign_id" value="{{ $selected->id }}">
                <input type="hidden" name="automatic" value="1">
                <div class="ec-auto-fields">
                    <label>Nome da sequência<input required name="name" minlength="3" maxlength="150" value="Continuidade de {{ $selected->name }}"></label>
                    <label>Intervalo após cada rodada (dias)<select name="delay_days" required>
                        <option value="3">3 dias</option><option value="4" selected>4 dias</option><option value="5">5 dias</option>
                        <option value="7">7 dias</option><option value="10">10 dias</option>
                    </select></label>
                    <label>Quantos follow-ups automáticos?<select name="max_rounds" required>
                        <option value="1">1 novo e-mail (2ª rodada)</option>
                        <option value="2" selected>2 novos e-mails (2ª e 3ª)</option>
                        <option value="3">3 novos e-mails (até 4ª)</option>
                        <option value="4">4 novos e-mails (até 5ª)</option>
                    </select></label>
                    <label>Assunto da mensagem<input required name="subject" minlength="3" maxlength="180" value="Retomando nosso contato — {{ $selected->name }}"></label>
                    <label class="ec-auto-full">Mensagem a reutilizar nas rodadas<textarea required name="body" rows="5" minlength="10" maxlength="12000">Olá, tudo bem? Gostaria de dar continuidade ao nosso contato anterior. Podemos conversar sobre como a ExportControl pode ajudar sua empresa? Fico à disposição.</textarea></label>
                </div>
                <label class="ec-auto-consent"><input type="checkbox" name="confirm_auto" value="1" required>
                    <span>Autorizo o envio automático das rodadas definidas, para os contatos elegíveis, respeitando respostas, devoluções, descadastros, janela de envio e cotas SMTP.</span></label>
                <p class="ec-auto-note">O agendador só iniciará novas rodadas com o envio real autorizado no ambiente e o monitoramento IMAP atualizado. Nenhum envio será feito ao salvar esta configuração.</p>
                <button type="submit" class="ec-auto-submit">Salvar continuidade automática</button>
            </form>
        </details>
    @endif
</section>
