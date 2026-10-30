<?php

namespace App\Providers;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

/** EC_A242_TRUSTED_PRIVATE_PROXY: configuração após o carregamento do Laravel. */
final class ProspectorPrivateHttpsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A2541_VITE_PROD_ISOLATION: em producao, nunca usar HMR de localhost.
        // Mesmo se npm run dev recriar public/hot, @vite usa o build versionado.
        if ($this->app->isProduction()) {
            Vite::useHotFile(
                storage_path('framework/prospector-production-hmr-disabled.hot')
            );
            URL::forceScheme('https');
        }

        // Aqui a configuração já está carregada; NÃO usar config() em withMiddleware().
        $configured = config('prospector_private_https.trusted_proxies', []);

        if (! is_array($configured)) {
            return;
        }

        $proxies = [];

        foreach ($configured as $proxy) {
            if (is_string($proxy) && filter_var($proxy, FILTER_VALIDATE_IP) !== false) {
                $proxies[] = $proxy;
            }
        }

        if ($proxies === []) {
            return;
        }

        // Sem '*' e sem X-Forwarded-For: apenas o proxy privado conhecido.
        TrustProxies::at($proxies);
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_HOST,
        );
    }
}
