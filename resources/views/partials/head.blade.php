<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
{{-- PACOTE_35_LEADS_GESTAO_CORRECAO_REAL: final scoped layer AFTER Vite, no rebuild needed. --}}
<link rel="stylesheet" href="{{ asset('css/leads-gestao-v35.css') }}?v=35" data-ec-leads-visual="v35">

{{-- Configurações U01/U02/U03 — escopo limitado a estas rotas. --}}
@if (request()->routeIs('profile.edit', 'security.edit', 'appearance.edit'))
    <!-- EC_SETTINGS_FIGMA_V63 -->
    <link rel="stylesheet" href="{{ asset('css/ec-settings-figma-v63.css') }}?v=63" data-ec-settings="figma-v63">
@endif

{{-- EC_THEME_GLOBAL_V64: após Vite/V63, em toda a aplicação. --}}
<link rel="stylesheet" href="{{ asset('css/ec-theme-unified-v64.css') }}?v=64.12" data-ec-theme-unified="v64">
