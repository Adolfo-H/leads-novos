<svg class="pr-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name ?? '')
        @case('search')
            <circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/>
            @break
        @case('history')
            <path d="M3 11a9 9 0 1 1 2.5 7M3 5v6h6M12 7v5l3 2"/>
            @break
        @case('sliders')
            <path d="M4 7h7m4 0h5M4 17h3m4 0h9"/><circle cx="13" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>
            @break
        @case('arrow')
            <path d="M4 12h16m-6-6 6 6-6 6"/>
            @break
        @case('back')
            <path d="M20 12H4m6-6-6 6 6 6"/>
            @break
        @case('refresh')
            <path d="M20 7v5h-5M4 17v-5h5M5.3 7a7.5 7.5 0 0 1 12.4-2L20 8M4 16l2.3 3a7.5 7.5 0 0 0 12.4-2"/>
            @break
        @case('download')
            <path d="M12 3v12m-4-4 4 4 4-4M4 15v5h16v-5"/>
            @break
        @case('alert')
            <path d="m12 3 10 18H2Z"/><path d="M12 9v5m0 3v.1"/>
            @break
        @case('check')
            <circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>
            @break
        @case('list')
            <rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>
            @break
        @default
            <circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v.1"/>
    @endswitch
</svg>
