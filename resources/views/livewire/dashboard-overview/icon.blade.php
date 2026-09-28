{{-- Dashboard v3: ícones locais, sem dependência externa. --}}
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('company')
            <path d="M4 21V5l9-2v18M13 9h7v12M2 21h20M7 8h2M7 12h2M7 16h2M16 13h1M16 17h1" />
            @break
        @case('establishment')
            <path d="M3 10h18l-2-6H5l-2 6ZM5 10v10h14V10M9 20v-6h6v6" />
            @break
        @case('check')
            <path d="m8 12 3 3 5-6" /><circle cx="12" cy="12" r="9" />
            @break
        @case('activity')
            <rect x="4" y="3" width="16" height="18" rx="2" /><path d="M8 8h8M8 12h8M8 16h5" />
            @break
        @case('mail')
            <rect x="3" y="5" width="18" height="14" rx="3" /><path d="m4 7 8 6 8-6" />
            @break
        @case('phone')
            <path d="M8 3H5a2 2 0 0 0-2 2c0 8.8 7.2 16 16 16a2 2 0 0 0 2-2v-3l-5-2-2 2c-3-1.5-4.5-3-6-6l2-2-2-5Z" />
            @break
        @case('refresh')
            <path d="M20 7v5h-5M4 17v-5h5" /><path d="M6 6a8 8 0 0 1 13 2l1 4M4 12l1 4a8 8 0 0 0 13 2" />
            @break
        @case('search')
            <circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 5 5" />
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14" />
            @break
        @case('close')
            <path d="m6 6 12 12M6 18 18 6" />
            @break
        @case('arrow')
            <path d="M5 12h14m-5-5 5 5-5 5" />
            @break
        @case('pin')
            <path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 0 1 14 0Z" /><circle cx="12" cy="10" r="2.3" />
            @break
    @endswitch
</svg>
