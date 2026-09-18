<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
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

        if (
            $user
                ->isCommercialManager()
        ) {
            return $next(
                $request
            );
        }

        $company =
            $request->route(
                'company'
            );

        abort_unless(
            $company instanceof Company,
            404
        );

        $allowed =
            $company
                ->leadWorkState()
                ->where(
                    'assigned_user_id',
                    $user->id
                )
                ->exists();

        abort_unless(
            $allowed,
            403
        );

        return $next(
            $request
        );
    }
}
