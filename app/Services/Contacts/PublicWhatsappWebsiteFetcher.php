<?php

namespace App\Services\Contacts;

/** Facade para injetar um fetcher simulado nos testes, sem acessar a rede. */
interface PublicWhatsappWebsiteFetcher
{
    /** @return array{url:string,html:string}|null */
    public function get(string $registeredHost, string $path = '/'): ?array;
}
