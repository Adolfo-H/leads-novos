<?php

use App\Http\Middleware\EnsureCommercialManager;
use App\Http\Middleware\EnsureCommercialWorkspace;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('allows sellers and managers through the operational workspace middleware', function () {
    $middleware = new EnsureCommercialWorkspace;
    foreach (['seller', 'manager'] as $role) {
        $user = new User;
        $user->forceFill(['commercial_role' => $role]);
        $request = Request::create('/empresas', 'GET');
        $request->setUserResolver(static fn () => $user);
        expect($middleware->handle($request, static fn () => new Response('ok'))->getStatusCode())->toBe(200);
    }
});

it('does not permit sellers through manager-only middleware', function () {
    $seller = new User;
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER]);
    $request = Request::create('/acessos/solicitacoes', 'GET');
    $request->setUserResolver(static fn () => $seller);
    expect(fn () => (new EnsureCommercialManager)->handle($request, static fn () => new Response('unexpected')))
        ->toThrow(HttpException::class);
});

it('allows operational routes to sellers but leaves managerial routes protected', function () {
    foreach (['prospecting.index', 'imports.index', 'companies.index', 'contacts.index', 'contacts.receita', 'campaigns.index'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull();
        expect($route->gatherMiddleware())->toContain('commercial.workspace');
    }
    foreach (['leads.management', 'access.requests.index', 'access.requests.approve', 'access.requests.reject'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull();
        expect($route->gatherMiddleware())->toContain('commercial.manager');
    }
});

it('permits sellers to read every company without giving them management rights', function () {
    $seller = new User;
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER]);
    $company = new Company;
    expect(Gate::forUser($seller)->allows('view', $company))->toBeTrue();
    expect(Gate::forUser($seller)->allows('manageData', $company))->toBeFalse();
    expect(Gate::forUser($seller)->allows('forceResearchExports', $company))->toBeFalse();
});
