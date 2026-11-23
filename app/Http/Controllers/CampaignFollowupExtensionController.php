<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupDates;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** EC_FOLLOWUP_EXTEND_V2: reautoriza UMA rodada futura, nunca inicia SMTP ao salvar. */
final class CampaignFollowupExtensionController extends Controller
{
    public function store(Request $request, int $campaign, CampaignFollowupDates $calendar): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isCommercialManager(), 403);

        $data = $request->validate([
            'sequence_id' => ['required', 'integer', 'min:1'],
            'next_date' => ['required', 'date_format:Y-m-d'],
            'confirm' => ['accepted'],
        ]);

        $date = (string) $data['next_date'];
        $due = $calendar->at($date);
        if ($due === null || ! $due->greaterThan(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages([
                'next_date' => 'Escolha uma data futura para iniciar às 08:00 (horário de Brasília).',
            ]);
        }

        $round = DB::transaction(function () use ($campaign, $data, $date, $calendar): int {
            // Mesma ordem de bloqueios usada pelo motor automático: gate -> sequência.
            $gate = DB::table('email_campaign_dispatch_gates')
                ->where('id', 1)->lockForUpdate()->first();
            abort_unless($gate !== null, 503);

            $plan = DB::table('email_campaign_sequences')
                ->where('id', (int) $data['sequence_id'])
                ->where('email_campaign_id', $campaign)
                ->lockForUpdate()->first();
            abort_unless($plan !== null, 404);

            $sent = (int) $plan->sent_rounds;
            $limit = (int) $plan->max_rounds;
            if (! (bool) $plan->automatic || (string) $plan->status !== 'finished'
                || $sent !== $limit || $sent < 1 || $sent >= 4) {
                throw ValidationException::withMessages([
                    'next_date' => 'A sequência mudou ou ainda possui rodada programada. Atualize a página antes de continuar.',
                ]);
            }
            if (! $calendar->isExplicit($plan)) {
                throw ValidationException::withMessages([
                    'next_date' => 'Esta sequência é do modelo antigo. Revise as datas antes de autorizar outra rodada.',
                ]);
            }

            $root = EmailCampaign::query()->findOrFail($campaign);
            abort_unless($root->followup_root_campaign_id === null && $root->status === 'completed', 422);
            $previous = EmailCampaign::query()->find((int) $plan->last_campaign_id);
            if ($previous === null
                || (int) $previous->followup_root_campaign_id !== $campaign
                || (int) $previous->followup_round !== $sent + 1
                || $previous->status !== 'completed' || $previous->completed_at === null) {
                throw ValidationException::withMessages([
                    'next_date' => 'A rodada anterior ainda não foi concluída. Atualize a página quando finalizar.',
                ]);
            }

            $nextRound = $sent + 2;
            if (EmailCampaign::query()->where('followup_root_campaign_id', $campaign)
                ->where('followup_round', $nextRound)->exists()) {
                throw ValidationException::withMessages([
                    'next_date' => 'Já existe uma campanha para esta rodada. Nenhum novo disparo foi autorizado.',
                ]);
            }

            // Reutiliza o validador do próprio sistema (última aceitação SMTP,
            // conclusão da anterior, data futura e 08:00 de Brasília).
            $dates = $calendar->dates($plan);
            $dates[$nextRound] = $date;
            $candidate = (object) ['send_dates' => json_encode($dates, JSON_THROW_ON_ERROR)];
            if ($calendar->state($candidate, $nextRound, $previous) !== 'scheduled') {
                throw ValidationException::withMessages([
                    'next_date' => 'A data deve ser posterior à conclusão e à última aceitação SMTP da rodada anterior.',
                ]);
            }

            DB::table('email_campaign_sequences')->where('id', $plan->id)
                ->where('status', 'finished')
                ->update([
                    'max_rounds' => $sent + 1,
                    'send_dates' => json_encode($dates, JSON_THROW_ON_ERROR),
                    'send_time_local' => '08:00',
                    'status' => 'active',
                    'updated_at' => now('UTC'),
                ]);

            return $nextRound;
        }, 3);

        return redirect()->to(route('campaigns.index', ['campanha' => $campaign]).'#proxima-rodada')
            ->with('followup_extension_ok', $round.'ª rodada programada para '.
                CarbonImmutable::parse($date, 'America/Sao_Paulo')->format('d/m/Y').
                ' às 08:00. Nenhum e-mail foi enviado ao salvar.');
    }
}
