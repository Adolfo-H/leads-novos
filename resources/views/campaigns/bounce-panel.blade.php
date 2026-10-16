{{-- EC_CAMPAIGNS_V7_BOUNCES — resultados confirmados de DSNs, nunca mensagens por assunto. --}}
<section class="ecm7-bounces" aria-label="Relatórios de e-mails devolvidos">
    <header class="ecm7-bounces-heading">
        <div>
            <span class="ecm6-eyebrow">ENTREGA / RELATÓRIOS DSN</span>
            <h2>E-mails devolvidos</h2>
            <p>Apenas devoluções vinculadas ao Message-ID de envios registrados. Falhas temporárias não bloqueiam automaticamente um contato.</p>
        </div>
        <span class="ecm6-pill">Monitoramento IMAP</span>
    </header>
    <div class="ecm7-bounces-stats">
        <div><small>Endereço inválido</small><strong>{{ $this->bounceSummary['invalid'] }}</strong><span>Bloqueio automático para campanhas comerciais</span></div>
        <div><small>Falhas temporárias</small><strong>{{ $this->bounceSummary['temporary'] }}</strong><span>Somente acompanhamento</span></div>
        <div><small>Outras rejeições</small><strong>{{ $this->bounceSummary['permanent_other'] }}</strong><span>Exigem análise antes de agir</span></div>
    </div>
    <div class="ecm7-bounces-list" role="region" aria-label="Últimas devoluções registradas" tabindex="0">
        <table>
            <thead><tr><th>Destinatário</th><th>Campanha</th><th>Resultado</th><th>Relatório</th></tr></thead>
            <tbody>
                @forelse ($this->recentBounces as $bounce)
                    <tr>
                        <td><strong>{{ $bounce->final_recipient }}</strong><small>{{ $bounce->created_at?->format('d/m/Y H:i') }}</small></td>
                        <td>{{ $bounce->campaign?->name ?? 'Campanha' }}</td>
                        <td><span class="ecm7-bounce-tag ecm7-bounce-{{ $bounce->classification }}">{{ match ($bounce->classification) { 'invalid' => 'Inválido / bloqueado*', 'temporary' => 'Temporária', default => 'Revisar' } }}</span><small>{{ $bounce->status_code }}</small></td>
                        <td><span title="{{ $bounce->diagnostic }}">{{ $bounce->diagnostic ?: 'Sem detalhes adicionais' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="ecm7-bounces-empty">Nenhum relatório DSN correlacionado foi recebido até o momento.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="ecm7-bounces-footnote">* Bloqueio automático apenas para rejeições de endereço inválido em envios <strong>comerciais</strong>. Relatórios de testes piloto são registrados, mas não bloqueiam contatos comerciais. O aceite SMTP não equivale à entrega.</p>
</section>
