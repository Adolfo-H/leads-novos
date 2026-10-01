<?php

namespace App\Providers;

use App\Contracts\CnpjDataProvider;
use App\Contracts\CnpjGroupDataProvider;
use App\Contracts\CrmCompanyProvider;
use App\Contracts\ExportResearchProvider;
use App\Http\Middleware\EnsureCommercialCompanyAccess;
use App\Http\Middleware\EnsureCommercialManager;
use App\Models\Company;
use App\Policies\CompanyPolicy;
use App\Services\Providers\BrasilApiCnpjProvider;
use App\Services\Providers\HubSpotCrmCompanyProvider;
use App\Services\Providers\ReceitaLocalCnpjGroupProvider;
use App\Services\Providers\TavilyExportResearchProvider;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

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

        Gate::policy(
            Company::class,
            CompanyPolicy::class,
        );

        /*
         * Middlewares aplicados na rota inicial
         * precisam continuar valendo nas
         * requisições seguintes do Livewire.
         */
        Livewire::addPersistentMiddleware([
            EnsureEmailIsVerified::class,
            EnsureCommercialManager::class,
            EnsureCommercialCompanyAccess::class,
        ]);
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
