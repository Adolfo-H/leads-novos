<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupDates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** EC_FOLLOWUP_CANCEL_NEXT_V3: apenas rodadas ainda nao iniciadas; preserva todos os envios anteriores. */
final class CampaignFollowupCancelNextController extends Controller
{
    public function store(Request $request, int $campaign, CampaignFollowupDates $calendar): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isCommercialManager(), 403);

        $data = $request->validate([
            'sequence_id' => ['required', 'integer', 'min:1'],
            'next_round' => ['required', 'integer', 'min:3', 'max:5'],
            'confirm' => ['accepted'],
        ]);

        $stoppedRound = DB::transaction(function () use ($data, $campaign, $calendar): int {
            // Ordem de locks identica ao executor automatico: gate -> plano.
            $gate = DB::table('email_campaign_dispatch_gates')
                ->where('id', 1)->lockForUpdate()->first();
            abort_unless($gate !== null, 503);

            $plan = DB::table('email_campaign_sequences')
                ->where('id', (int) $data['sequence_id'])
                ->where('email_campaign_id', $campaign)
                ->lockForUpdate()->first();
            abort_unless($plan !== null, 404);

            $sent = (int) $plan->sent_rounds;
            $max = (int) $plan->max_rounds;
            $nextRound = $sent + 2;
            if (! (bool) $plan->automatic
                || ! in_array((string) $plan->status, ['active', 'paused'], true)
                || $sent < 1 || $max <= $sent
                || $nextRound !== (int) $data['next_round']
                || ! $calendar->isExplicit($plan)) {
                throw ValidationException::withMessages([
                    'cancel_next' => 'A programação mudou ou não existe uma próxima rodada cancelável. Atualize a página.',
                ]);
            }

            $root = EmailCampaign::query()->findOrFail($campaign);
            abort_unless($root->followup_root_campaign_id === null, 422);
            // Uma campanha ja criada pode ter sido colocada na fila. Jamais cancela
            // uma campanha existente ou modifica seu status/SMTP.
            $futureExists = EmailCampaign::query()
                ->where('followup_root_campaign_id', $campaign)
                ->where('followup_round', '>=', $nextRound)
                ->exists();
            if ($futureExists) {
                throw ValidationException::withMessages([
                    'cancel_next' => 'A próxima rodada já foi criada. Abra essa campanha para consultar seu estado; nenhum envio foi cancelado.',
                ]);
            }

            $dates = $calendar->dates($plan);
            foreach (array_keys($dates) as $round) {
                if ($round >= $nextRound) {
                    unset($dates[$round]);
                }
            }

            // Sent>=1 garante max_rounds>=1 e permite nova autorizacao futura
            // pelo modulo de extensao ja instalado (sem criar/alterar campanhas).
            DB::table('email_campaign_sequences')->where('id', $plan->id)
                ->whereIn('status', ['active', 'paused'])
                ->update([
                    'status' => 'finished',
                    'max_rounds' => $sent,
                    'send_dates' => json_encode($dates, JSON_THROW_ON_ERROR),
                    'updated_at' => now('UTC'),
                ]);

            return $nextRound;
        }, 3);

        return redirect()->to(route('campaigns.index', ['campanha' => $campaign]).'#ecv5-cronograma')
            ->with('followup_cancel_ok', 'A '.$stoppedRound.'ª rodada e as futuras datas ainda não iniciadas foram canceladas. Os envios anteriores permanecem inalterados.');
    }
}
