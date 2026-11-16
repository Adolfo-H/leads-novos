<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" type="image/png" sizes="16x16" href="/images/brand/exportcontrol-favicon-v285-16.png?v=28.5">
<link rel="icon" type="image/png" sizes="32x32" href="/images/brand/exportcontrol-favicon-v285-32.png?v=28.5">
<link rel="icon" type="image/x-icon" href="/favicon.ico?v=28.5">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=28.5">

@fonts

@livewireStyles
@vite(['resources/css/app.css', 'resources/js/app.js'])
{{-- EC_PACK_20261104: visual exclusivo de campanhas e contagem de devoluções. --}}
@if (request()->routeIs('campaigns.*'))
    <link rel="stylesheet" href="{{ asset('css/ec-campaign-followups.css') }}?v=1.2">
@endif
@fluxAppearance
{{-- EC_CAMPAIGNS_FOLLOWUP_V294: somente layout de campanhas, sem rebuild Vite. --}}
<link rel="stylesheet" href="{{ asset('css/ec-campaign-tools.css') }}?v=29.4" data-ec-campaign-tools="v294">
{{-- EC_CAMPAIGNS_BOUNCE_VISIBILITY_V29: CSS isolado de campanha. --}}
<link rel="stylesheet" href="{{ asset('css/ec-campaign-bounces.css') }}?v=29.1" data-ec-campaign-bounces="v29">
{{-- EC_APPEARANCE_V65_A243: controla os tres visuais sem alterar login. --}}
<script src="/js/ec-appearance-modes.js?v=65.1"></script>
{{-- PACOTE_35_LEADS_GESTAO_CORRECAO_REAL: final scoped layer AFTER Vite, no rebuild needed. --}}
<link rel="stylesheet" href="{{ asset('css/leads-gestao.css') }}?v=35" data-ec-leads-visual="v35">

{{-- Configurações U01/U02/U03 — escopo limitado a estas rotas. --}}
@if (request()->routeIs('profile.edit', 'security.edit', 'appearance.edit'))
    <!-- EC_SETTINGS_FIGMA_V63 -->
    <link rel="stylesheet" href="{{ asset('css/ec-settings-figma.css') }}?v=63" data-ec-settings="figma-v63">
@endif

{{-- EC_THEME_GLOBAL_V64: após Vite/V63, em toda a aplicação. --}}
<link rel="stylesheet" href="{{ asset('css/ec-theme-unified.css') }}?v=64.12" data-ec-theme-unified="v64">
<link rel="stylesheet" href="/css/ec-appearance-modes.css?v=65.1" data-ec-appearance-modes="v65">
<link rel="stylesheet" href="/css/ec-theme-contrast-black.css?v=66.1" data-ec-theme-black="v66">
<link rel="stylesheet" href="/css/ec-dark-contrast.css?v=67.1" data-ec-dark-contrast="v67">
<link rel="stylesheet" href="/css/ec-dark-fixes.css?v=68.1" data-ec-dark-fixes="v68">
<link rel="stylesheet" href="/css/ec-dark-repair.css?v=69.1" data-ec-dark-repair="v69">
<link rel="stylesheet" href="/css/ec-dark-final.css?v=71.1" data-ec-dark-v71="v71">
<script defer src="/js/ec-dark-final.js?v=71.1" data-ec-dark-v71-js="v71"></script>
<link rel="stylesheet" href="/css/ec-ui-polish.css?v=72.1" data-ec-ui-polish="v72">
<link rel="stylesheet" href="/css/ec-ui-dark-workspaces.css?v=73.1" data-ec-ui-dark="v73">
<link rel="stylesheet" href="/css/ec-ui-dark-components.css?v=74.16" data-ec-ui-dark="v74">
<link rel="stylesheet" href="/css/ec-tax-regime.css?v=27.1" data-ec-tax-regime="v27">
<link rel="stylesheet" href="/css/ec-leads-repros.css?v=255.1" data-ec-leads-repro="v255">
<script defer src="/js/ec-ui-hardfix.js?v=74.16" data-ec-hardfix="A24.16"></script>

{{-- EC_FOLLOWUP_VISIBILITY_V3: visual de programação, sem alterar SMTP. --}}
@if (request()->routeIs('campaigns.*'))
    <link rel="stylesheet" href="{{ asset('css/ec-campaign-followup-forecast.css') }}?v=3.0" data-ec-followup-v3="true">
@endif

{{-- EC_FOLLOWUP_VISIBILITY_V4: exclusivamente na area de campanhas. --}}
@if (request()->routeIs('campaigns.*'))
    <link rel="stylesheet" href="{{ asset('css/ec-campaign-followup-timeline.css') }}?v=4.1" data-ec-followup-v4="true">
@endif

{{-- EC_AUTO_FOLLOWUP_V5: somente campanhas. --}}
@if (request()->routeIs('campaigns.*'))
<link rel="stylesheet" href="{{ asset('css/ec-campaign-followup.css') }}?v=5.1" data-ec-followup-v5="true">
@endif
