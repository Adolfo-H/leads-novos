<?php

return [
    // Buscas oficiais/permitidas; nunca consome conta WhatsApp nem faz scraping de LinkedIn/Google Maps.
    'brave_token' => env('WA_PUBLIC_BRAVE_SEARCH_API_KEY', ''),
];
