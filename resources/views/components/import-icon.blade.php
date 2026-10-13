@props(['name' => 'info'])

<svg {{ $attributes->merge(['class' => 'ecimv2-icon']) }} xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('file-plus')
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 12v6m-3-3h6"/>
            @break
        @case('file-text')
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/>
            @break
        @case('check-circle')
            <circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>
            @break
        @case('user-round')
            <circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>
            @break
        @case('copy')
            <rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>
            @break
        @case('alert-triangle')
            <path d="m10.3 3.7-8.1 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.8-3.3l-8.1-14a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4m0 4h.01"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
            @break
        @case('hourglass')
            <path d="M6 3h12M6 21h12M7 3c0 5 5 5 5 9s-5 4-5 9M17 3c0 5-5 5-5 9s5 4 5 9"/>
            @break
        @case('refresh-cw')
            <path d="M20 11a8 8 0 0 0-14.5-4.5L3 9m0-5v5h5"/><path d="M4 13a8 8 0 0 0 14.5 4.5L21 15m0 5v-5h-5"/>
            @break
        @case('list-checks')
            <rect x="4" y="3" width="16" height="18" rx="2"/><path d="m8 9 1.3 1.3L12 7.5M14 9h3m-9 6 1.3 1.3L12 13.5m2 1.5h3"/>
            @break
        @case('cloud-upload')
            <path d="M20 16.6a4.8 4.8 0 0 0-.8-9.4A7.5 7.5 0 0 0 5 9a4.6 4.6 0 0 0 0 9.2h3"/><path d="M12 21V11m-4 4 4-4 4 4"/>
            @break
        @case('search')
            <circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>
            @break
        @case('arrow-right')
            <path d="M4 12h16m-6-6 6 6-6 6"/>
            @break
        @case('chevron-right')
            <path d="m9 18 6-6-6-6"/>
            @break
        @case('chevron-down')
            <path d="m6 9 6 6 6-6"/>
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14"/>
            @break
        @case('lightbulb')
            <path d="M9 18h6m-5 3h4M9 14a6 6 0 1 1 6 0c-1 1-1 2-1 4h-4c0-2 0-3-1-4Z"/>
            @break
        @case('bar-chart')
            <path d="M3 3v18h18M7 17v-4m5 4V7m5 10v-7"/>
            @break
        @default
            <circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/>
    @endswitch
</svg>
