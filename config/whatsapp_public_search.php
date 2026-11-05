<?php

return [
    // Buscas oficiais/permitidas; nunca consome conta WhatsApp nem faz scraping de LinkedIn/Google Maps.
    'brave_token' => env('WA_PUBLIC_BRAVE_SEARCH_API_KEY', ''),
    // EC_WA_V16.2: reutiliza a chave das pesquisas de exportação.
    'tavily_token' => env('WA_PUBLIC_TAVILY_API_KEY') ?: env('TAVILY_API_KEY', ''),
];
