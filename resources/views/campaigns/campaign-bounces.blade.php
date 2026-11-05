{{-- Devoluções relacionadas apenas à campanha selecionada, sem contar relatórios de outras rodadas. --}}
@php
    $ecBounceRows = \App\Models\EmailCampaignBounce::query()
        ->where('email_campaign_id', $selected->id)
        ->latest('received_at')->limit(50)->get();
@endphp
<div class="ec-campaign-bounces" aria-label="Devoluções da rodada selecionada">
    <p>Resultados identificados por relatórios DSN associados ao Message-ID. Aceite SMTP não garante entrega.</p>
    @if ($ecBounceRows->isEmpty())
        <div class="ec-campaign-bounces-empty">Nenhuma devolução confirmada nesta rodada.</div>
    @else
        <div class="ec-campaign-bounces-scroll"><table>
            <thead><tr><th>Destinatário</th><th>Tipo</th><th>Código</th><th>Data</th></tr></thead>
            <tbody>
                @foreach ($ecBounceRows as $bounce)
                    <tr><td>{{ $bounce->final_recipient }}</td>
                        <td>{{ match ($bounce->classification) { 'invalid' => 'Endereço inválido', 'temporary' => 'Temporária', default => 'Exige análise' } }}</td>
                        <td>{{ $bounce->status_code ?: '—' }}</td>
                        <td>{{ $bounce->received_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        @if ($ecBounceRows->count() === 50)<p>Exibindo as 50 devoluções mais recentes.</p>@endif
    @endif
</div>
