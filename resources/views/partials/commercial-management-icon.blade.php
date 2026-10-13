{{-- Ícones decorativos do painel comercial. Nenhuma dependência externa. --}}
<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.85"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('active_total')
            <ellipse cx="12" cy="5" rx="8" ry="3" />
            <path d="M4 5v7c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7" />
            @break
        @case('opportunities_total')
            <circle cx="12" cy="12" r="9" /><circle cx="12" cy="12" r="5" />
            <path d="M12 12 21 3M17 3h4v4" />
            @break
        @case('overdue_total')
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v6l4 2" />
            @break
        @case('due_today_total')
            <rect x="3" y="5" width="18" height="16" rx="2" />
            <path d="M7 3v4M17 3v4M3 10h18M8 15h3m3 0h2" />
            @break
        @case('attention_total')
            <path d="M10.3 3.5a2 2 0 0 1 3.4 0L22 18a2 2 0 0 1-1.7 3H3.7A2 2 0 0 1 2 18z" />
            <path d="M12 9v5m0 3h.01" />
            @break
        @case('unassigned_total')
            <circle cx="9" cy="8" r="4" /><path d="M2 21v-2a7 7 0 0 1 14 0v2M19 9v6m-3-3h6" />
            @break
        @default
            <rect x="4" y="4" width="16" height="16" rx="3" />
    @endswitch
</svg>
