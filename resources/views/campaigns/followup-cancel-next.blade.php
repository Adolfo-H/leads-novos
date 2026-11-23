{{-- EC_FOLLOWUP_CANCEL_NEXT_V3: nenhuma acao automatica; POST protegido e confirmado. --}}
@if($canEdit && $schedule !== null
    && (bool) ($schedule['automatic'] ?? false)
    && in_array((string) $schedule['status'], ['active', 'paused'], true)
    && (int) $schedule['sent_followups'] >= 1
    && (int) $schedule['remaining_followups'] > 0
    && (bool) ($schedule['calendar_mode'] ?? false))
    @php
        $ecCancelRound = (int) $schedule['next_round'];
        $ecCancelRemaining = (int) $schedule['remaining_followups'];
    @endphp
    <section class="ecv5-settings" id="cancelar-proxima-rodada" aria-label="Cancelar programação de próximos e-mails" style="margin-top:16px">
        <div class="ecv5-section-head">
            <div>
                <h3>Cancelar {{ $ecCancelRemaining === 1 ? 'a '.$ecCancelRound.'ª rodada' : 'os próximos retornos' }}</h3>
                <p>Isso impede criar novas rodadas deste acompanhamento. As campanhas já iniciadas, seus destinatários e os resultados SMTP ficam preservados.</p>
            </div>
        </div>
        @error('cancel_next')<p class="ecv5-error" role="alert">{{ $message }}</p>@enderror
        @if(session('followup_cancel_ok'))
            <p class="ecv5-feedback is-success" role="status">{{ session('followup_cancel_ok') }}</p>
        @endif
        <form method="POST" action="{{ route('campaigns.followup.cancel-next', ['campaign' => $root->id]) }}" class="ecv5-form">
            @csrf
            <input type="hidden" name="sequence_id" value="{{ (int) $schedule['id'] }}">
            <input type="hidden" name="next_round" value="{{ $ecCancelRound }}">
            <label style="display:flex;align-items:flex-start;gap:9px">
                <input type="checkbox" name="confirm" value="1" required style="width:auto;margin-top:4px">
                <span>Confirmo o cancelamento {{ $ecCancelRemaining === 1 ? 'da '.$ecCancelRound.'ª rodada' : 'das '.$ecCancelRemaining.' próximas rodadas' }} ainda não iniciadas. Não cancelar campanhas em andamento.</span>
            </label>
            <div class="ecv5-save-row">
                <span>Se decidir fazer outro contato depois, poderá programar uma nova data explicitamente.</span>
                <button type="submit" class="ecv5-pause" style="border:1px solid #d9a3a3;color:#9b4141;background:#fff5f5;padding:10px 14px;border-radius:8px">Cancelar {{ $ecCancelRemaining === 1 ? $ecCancelRound.'ª rodada' : 'próximas rodadas' }}</button>
            </div>
        </form>
    </section>
@endif
