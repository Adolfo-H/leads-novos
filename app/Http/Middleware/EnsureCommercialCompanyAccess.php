<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCommercialCompanyAccess
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $user =
            $request->user();

        abort_unless(
            $user instanceof User,
            403
        );

        $company =
            $request->route(
                'company'
            );

        abort_unless(
            $company instanceof Company,
            404
        );

        Gate::forUser(
            $user
        )->authorize(
            'view',
            $company,
        );

        return $next(
            $request
        );
    }
}
