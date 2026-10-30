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
{{-- EC_APPEARANCE_V65_A243: controla os tres visuais sem alterar login. --}}
<script src="/js/ec-appearance-modes-v65.js?v=65.1"></script>
{{-- PACOTE_35_LEADS_GESTAO_CORRECAO_REAL: final scoped layer AFTER Vite, no rebuild needed. --}}
<link rel="stylesheet" href="{{ asset('css/leads-gestao-v35.css') }}?v=35" data-ec-leads-visual="v35">

{{-- Configurações U01/U02/U03 — escopo limitado a estas rotas. --}}
@if (request()->routeIs('profile.edit', 'security.edit', 'appearance.edit'))
    <!-- EC_SETTINGS_FIGMA_V63 -->
    <link rel="stylesheet" href="{{ asset('css/ec-settings-figma-v63.css') }}?v=63" data-ec-settings="figma-v63">
@endif

{{-- EC_THEME_GLOBAL_V64: após Vite/V63, em toda a aplicação. --}}
<link rel="stylesheet" href="{{ asset('css/ec-theme-unified-v64.css') }}?v=64.12" data-ec-theme-unified="v64">
<link rel="stylesheet" href="/css/ec-appearance-modes-v65.css?v=65.1" data-ec-appearance-modes="v65">
<link rel="stylesheet" href="/css/ec-theme-contrast-black-v66.css?v=66.1" data-ec-theme-black="v66">
<link rel="stylesheet" href="/css/ec-dark-contrast-v67.css?v=67.1" data-ec-dark-contrast="v67">
<link rel="stylesheet" href="/css/ec-dark-fixes-v68.css?v=68.1" data-ec-dark-fixes="v68">
<link rel="stylesheet" href="/css/ec-dark-repair-v69.css?v=69.1" data-ec-dark-repair="v69">
<link rel="stylesheet" href="/css/ec-dark-final-v71.css?v=71.1" data-ec-dark-v71="v71">
<script defer src="/js/ec-dark-final-v71.js?v=71.1" data-ec-dark-v71-js="v71"></script>
<link rel="stylesheet" href="/css/ec-ui-polish-v72.css?v=72.1" data-ec-ui-polish="v72">
<link rel="stylesheet" href="/css/ec-ui-dark-v73.css?v=73.1" data-ec-ui-dark="v73">
<link rel="stylesheet" href="/css/ec-ui-dark-v74.css?v=74.16" data-ec-ui-dark="v74">
<link rel="stylesheet" href="/css/ec-leads-repros-v255.css?v=255.1" data-ec-leads-repro="v255">
<script defer src="/js/ec-ui-hardfix-v74.js?v=74.16" data-ec-hardfix="A24.16"></script>
