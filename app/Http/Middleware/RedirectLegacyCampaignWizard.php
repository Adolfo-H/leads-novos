<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Mantém links legados ?nova=1 apontando para o único assistente HTTP. */
final class RedirectLegacyCampaignWizard
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && (string) $request->query('nova', '') === '1') {
            $model = (string) $request->query('modelo', 'initial');
            $origin = (string) $request->query('origem', 'all');
            $query = [
                'reiniciar' => '1',
                'modelo' => in_array($model, ['initial', 'followup', 'meeting'], true) ? $model : 'initial',
                'origem' => in_array($origin, ['all', 'hubspot', 'manual', 'receita'], true) ? $origin : 'all',
            ];
            if (is_string($request->query('busca')) && $request->query('busca') !== '') {
                $query['busca'] = mb_substr(trim((string) $request->query('busca')), 0, 120);
            }

            return redirect()->route('campaigns.wizard-v93', $query);
        }

        return $next($request);
    }
}
