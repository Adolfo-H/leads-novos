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
