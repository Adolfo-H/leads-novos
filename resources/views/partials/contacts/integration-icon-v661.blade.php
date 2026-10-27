{{-- Ícones vetoriais locais: sem biblioteca externa, sem rastreamento, sem dependência de rede. --}}
@if (($service ?? '') === 'hubspot')
    <svg class="c661-brand-icon c661-brand-icon--hubspot" width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
        <path d="M12 11.7 7.6 7.4M13.8 10.5l3.7-3.8M14.4 13.1l4.2 2" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
        <circle cx="12.4" cy="12.2" r="3.45" stroke="currentColor" stroke-width="2"/>
        <circle cx="6.5" cy="6.2" r="2.15" fill="currentColor"/>
        <circle cx="18.6" cy="5.6" r="2.25" fill="currentColor"/>
        <circle cx="20" cy="16.1" r="2.35" fill="currentColor"/>
    </svg>
@elseif (($service ?? '') === 'whatsapp')
    <svg class="c661-brand-icon c661-brand-icon--whatsapp" width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
        <path d="M20.4 11.8a8.4 8.4 0 0 1-12.5 7.4L3.1 20.6l1.5-4.7a8.4 8.4 0 1 1 15.8-4.1Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
        <path d="M8.4 8.2c.2-.3.5-.5.9-.4l1 .4c.3.1.4.4.4.7l-.3 1c.5 1.1 1.5 2.2 2.7 2.8l1-.3c.3-.1.6 0 .8.3l.5 1.1c.1.3 0 .6-.3.8-.5.5-1.3.8-2 .5-2.7-.9-4.9-3.1-5.8-5.8-.1-.5.2-1.1.5-1.1Z" fill="currentColor"/>
    </svg>
@endif
