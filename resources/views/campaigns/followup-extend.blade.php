{{-- EC_FOLLOWUP_EXTEND_V2: reabrir apenas UMA rodada futura concluída; sem Livewire para salvar. --}}
@if($canEdit && $schedule !== null && (bool) $schedule['automatic']
    && (string) $schedule['status'] === 'finished'
    && (int) $schedule['sent_followups'] >= 1
    && (int) $schedule['sent_followups'] === (int) $schedule['max_followups']
    && (int) $schedule['sent_followups'] < 4)
    @php
        $ecNextRound = (int) $schedule['sent_followups'] + 2;
        $ecPriorRound = $ecNextRound - 1;
        $ecPriorCompleted = (string) ($schedule['latest_campaign_status'] ?? '') === 'completed';
    @endphp
    <section id="proxima-rodada" class="ecv5-settings" aria-label="Programar mais uma rodada" style="margin-top:20px">
        <div class="ecv5-section-head">
            <div>
                <h3>Programar a {{ $ecNextRound }}ª rodada</h3>
                <p>Você já programou {{ $ecPriorRound }} rodadas no total. Quer fazer outro contato? Escolha a data desta próxima rodada.</p>
            </div>
        </div>
        @if(session('followup_extension_ok'))
            <p class="ecv5-feedback is-success" role="status">{{ session('followup_extension_ok') }}</p>
        @endif
        @if($ecPriorCompleted)
            <form method="POST" action="{{ route('campaigns.followup.extend', ['campaign' => $root->id]) }}" class="ecv5-form">
                @csrf
                <input type="hidden" name="sequence_id" value="{{ (int) $schedule['id'] }}">
                <div class="ecv5-form-cols">
                    <label>
                        <span>Data da {{ $ecNextRound }}ª rodada</span>
                        <input type="date" name="next_date" value="{{ old('next_date', '') }}" min="{{ now('America/Sao_Paulo')->format('Y-m-d') }}" required>
                        <small>O início será às <strong>08:00 (Brasília)</strong>. Não haverá envio fora da janela de 08:00–08:15.</small>
                    </label>
                    <div class="ecv5-read-only" style="padding:12px 15px;border:1px solid #b9e0d7;border-radius:8px;background:#eef9f5">
                        <strong>Quantidade após salvar: {{ $ecNextRound }} rodadas no total</strong>
                        <p>A {{ $ecPriorRound }}ª rodada permanece registrada. O assunto e a mensagem atuais serão reutilizados. Nenhum e-mail é enviado no momento do salvamento.</p>
                    </div>
                </div>
                <label style="display:flex;align-items:flex-start;gap:9px">
                    <input type="checkbox" name="confirm" value="1" required style="width:auto;margin-top:4px">
                    <span>Autorizo somente a {{ $ecNextRound }}ª rodada na data escolhida, sujeita a IMAP saudável e às proteções de destinatários.</span>
                </label>
                @error('next_date')<p role="alert" class="ecv5-error">{{ $message }}</p>@enderror
                @error('confirm')<p role="alert" class="ecv5-error">{{ $message }}</p>@enderror
                <div class="ecv5-save-row">
                    <span>Se a data vencer sem execução, será necessário reagendar. Respostas, descadastros, devoluções e envios incertos são excluídos do próximo público.</span>
                    <button type="submit" class="ecv5-save">Programar {{ $ecNextRound }}ª rodada</button>
                </div>
            </form>
        @else
            <p class="ecv5-read-only" role="status">A {{ $ecPriorRound }}ª rodada ainda está em processamento. O agendamento da próxima será liberado quando ela for concluída.</p>
        @endif
    </section>
@endif
