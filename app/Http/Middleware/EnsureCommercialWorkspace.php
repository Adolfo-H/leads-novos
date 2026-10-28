<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telas operacionais disponíveis a gestores e vendedores.
 * NÃO substitui commercial.manager em Gestão Comercial ou Administração.
 * Validado também nas atualizações Livewire via persistent middleware.
 */
final class EnsureCommercialWorkspace
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
                && ($user->isCommercialManager() || $user->isCommercialSeller()),
            403
        );

        return $next($request);
    }
}
