@php($attachmentEvidenceV29 = \App\Services\Proposals\ProposalAttachmentAcceptanceV29::evidence((int) $attachmentProposalId))
@if($attachmentEvidenceV29)
    <div class="card help" style="margin:12px 0"><h3>Aceite confirmado por anexo do HubSpot</h3><p>Conferido em {{ $attachmentEvidenceV29['checked_at'] ?? '—' }}. Arquivo original idêntico (SHA-256). Não substitui aceite formal.</p>
        @foreach($attachmentEvidenceV29['attachments'] ?? [] as $attachment)
            <p style="margin-top:8px"><b>{{ $attachment['original_name'] }}</b> · {{ \App\Services\Proposals\ProposalAttachmentAcceptanceV29::STAGES[$attachment['stage']] ?? $attachment['stage'] }}<br><small>Anexo {{ $attachment['file_id'] }} · Negócio {{ $attachment['deal_id'] }} · {{ $attachment['deal_name'] ?? '' }}</small></p>
            @if(ctype_digit((string) config('services.hubspot.portal_id')) && ctype_digit((string) $attachment['deal_id']))<a class="link" target="_blank" rel="noopener noreferrer" href="https://app.hubspot.com/contacts/{{ config('services.hubspot.portal_id') }}/record/0-3/{{ $attachment['deal_id'] }}">Abrir negócio no HubSpot</a>@endif
        @endforeach
    </div>
@endif
