<?php

namespace App\Providers;

use App\Contracts\CnpjDataProvider;
use App\Contracts\CnpjGroupDataProvider;
use App\Contracts\CrmCompanyProvider;
use App\Contracts\ExportResearchProvider;
use App\Services\Providers\BrasilApiCnpjProvider;
use App\Services\Providers\HubSpotCrmCompanyProvider;
use App\Services\Providers\ReceitaLocalCnpjGroupProvider;
use App\Services\Providers\TavilyExportResearchProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            CnpjDataProvider::class,
            BrasilApiCnpjProvider::class
        );

        $this->app->bind(
            CnpjGroupDataProvider::class,
            ReceitaLocalCnpjGroupProvider::class
        );
        $this->app->bind(
            CrmCompanyProvider::class,
            HubSpotCrmCompanyProvider::class
        );

        $this->app->bind(
            ExportResearchProvider::class,
            TavilyExportResearchProvider::class
        );

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
