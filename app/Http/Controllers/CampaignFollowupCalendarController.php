<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Edicao HTTP nativa: salva apenas planos; nao cria campanhas ou chamadas SMTP. */
final class CampaignFollowupCalendarController extends Controller
{
    public function save(Request $request, int $campaign, CampaignFollowupSettings $settings): RedirectResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->isCommercialManager()) {
            abort(403);
        }
        $root = EmailCampaign::query()->findOrFail($campaign);
        abort_unless($root->followup_root_campaign_id === null, 422);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'total_rounds' => ['required', 'integer', 'between:2,5'],
            'round_dates' => ['nullable', 'array'],
            'round_dates.*' => ['nullable', 'date_format:Y-m-d'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:12000'],
        ]);

        /** @var array<int|string, string> $dates */
        $dates = is_array($data['round_dates'] ?? null) ? $data['round_dates'] : [];
        $settings->save($actor, $campaign, [
            'name' => (string) $data['name'],
            'delay_days' => 5, // Legado nao e usado no modo de datas explicitas.
            'max_rounds' => ((int) $data['total_rounds']) - 1,
            'send_time_local' => '08:00',
            'first_send_date' => null,
            'send_dates' => $dates,
            'subject' => (string) $data['subject'],
            'body' => (string) $data['body'],
        ]);

        return redirect()->route('campaigns.index', ['campanha' => $campaign])
            ->with('followup_calendar_ok', 'Programacao salva. Os envios continuam sujeitos a confirmacao do horario, IMAP e demais protecoes.');
    }

    public function pause(Request $request, int $campaign, CampaignFollowupSettings $settings): RedirectResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->isCommercialManager()) {
            abort(403);
        }
        $root = EmailCampaign::query()->findOrFail($campaign);
        abort_unless($root->followup_root_campaign_id === null, 422);
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $plan = DB::table('email_campaign_sequences')->where('email_campaign_id', $campaign)
            ->where('automatic', true)->whereIn('status', ['active', 'paused'])->orderByDesc('id')->first();
        abort_unless($plan !== null, 404);

        $settings->changePause($actor, $campaign, (int) $plan->id, (bool) $data['paused']);

        return redirect()->route('campaigns.index', ['campanha' => $campaign]);
    }
}
