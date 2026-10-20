<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Campaigns\CampaignProductionReadiness;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Tela de evidências e limites; jamais altera CAMPAIGN_MAIL_LIVE_ENABLED. */
final class CampaignProductionReadinessController extends Controller
{
    public function index(CampaignProductionReadiness $service): View
    {
        $this->manager();

        return view('campaigns.production-readiness', ['report' => $service->snapshot()]);
    }

    public function save(Request $request): RedirectResponse
    {
        $user = $this->manager();
        if (config('campaign_mail.live_enabled')) {
            throw ValidationException::withMessages(['limits' => 'Desative os envios comerciais antes de alterar a homologação.']);
        }

        $data = $request->validate([
            'smtp_per_hour' => ['required', 'integer', 'min:1', 'max:100'],
            'smtp_per_day' => ['required', 'integer', 'min:1', 'max:500'],
            'smtp_evidence' => ['required', 'string', 'min:12', 'max:500'],
            'smtp_confirmed' => ['nullable', 'accepted'],
            'public_tested' => ['nullable', 'boolean'],
            'audience_reviewed' => ['nullable', 'boolean'],
            'bounce_reviewed' => ['nullable', 'boolean'],
        ]);

        DB::table('email_campaign_release_reviews')->updateOrInsert(
            ['id' => 1],
            [
                'smtp_per_hour' => (int) $data['smtp_per_hour'],
                'smtp_per_day' => (int) $data['smtp_per_day'],
                'smtp_evidence' => trim((string) $data['smtp_evidence']),
                'smtp_confirmed' => $request->boolean('smtp_confirmed'),
                'public_tested' => $request->boolean('public_tested'),
                'audience_reviewed' => $request->boolean('audience_reviewed'),
                'bounce_reviewed' => $request->boolean('bounce_reviewed'),
                'updated_by' => $user->id,
                'updated_at' => now('UTC'),
            ],
        );

        return redirect()->route('campaigns.production.index')
            ->with('campaign_release_saved', 'Registro de homologação atualizado. Nenhum envio foi habilitado.');
    }

    private function manager(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);

        return $user;
    }
}
