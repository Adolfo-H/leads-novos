<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Prepara rascunhos de follow-up. Nunca inicia campanhas nem envia e-mails. */
final class CampaignSequencePlanner
{
    public function create(User $user, EmailCampaign $source, string $name, int $days, string $subject, string $body): int
    {
        abort_unless($user->isCommercialManager(), 403);
        if ($days < 1 || $days > 90 || mb_strlen(trim($subject)) < 3 || mb_strlen(trim($body)) < 10) {
            throw ValidationException::withMessages(['sequence' => 'Informe 1 a 90 dias, assunto e corpo válidos.']);
        }
        // Compatibilidade: um rascunho pode conter histórico já enviado.
        // A segunda rodada permanece bloqueada até a conclusão em prepare().
        if (DB::table('email_campaign_sequences')->where('email_campaign_id', $source->id)
            ->whereIn('status', ['planned', 'prepared'])->exists()) {
            throw ValidationException::withMessages(['sequence' => 'Esta campanha já possui segunda rodada planejada.']);
        }
        if (! $source->recipients()->where('status', 'sent')->exists()) {
            throw ValidationException::withMessages(['sequence' => 'A campanha de origem não possui mensagens aceitas pelo SMTP.']);
        }

        return (int) DB::table('email_campaign_sequences')->insertGetId([
            'email_campaign_id' => $source->id,
            'name' => mb_substr(trim($name), 0, 150),
            'subject' => mb_substr(trim($subject), 0, 180),
            'body' => mb_substr(trim($body), 0, 12000),
            'delay_days' => $days,
            'status' => 'planned',
            'created_by' => $user->id,
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    public function prepare(User $user, int $sequenceId): ?int
    {
        abort_unless($user->isCommercialManager(), 403);

        // EC_CAMPAIGNS_FOLLOWUP_V294: preparar RASCUNHO não depende do gate SMTP.
        // Nenhuma mensagem é enfileirada ou enviada aqui.
        return DB::transaction(function () use ($sequenceId): ?int {
            $plan = DB::table('email_campaign_sequences')->where('id', $sequenceId)->lockForUpdate()->first();
            if ($plan === null || $plan->status !== 'planned') {
                return $plan !== null && $plan->draft_campaign_id !== null ? (int) $plan->draft_campaign_id : null;
            }
            $source = EmailCampaign::query()->findOrFail((int) $plan->email_campaign_id);
            $cutoff = now('UTC')->subDays((int) $plan->delay_days);
            if ($source->status !== 'completed'
                || ($source->completed_at !== null && Carbon::parse($source->completed_at)->gt($cutoff))) {
                return null;
            }
            // A carência conta do ÚLTIMO envio, não do primeiro.
            $lastSent = EmailCampaignRecipient::query()->where('email_campaign_id', $source->id)
                ->where('status', 'sent')->max('sent_at');
            if ($lastSent === null || Carbon::parse($lastSent)->gt($cutoff)) {
                return null;
            }

            $limit = max(1, min(500, (int) config('campaign_mail.max_recipients', 100)));
            // Filtragem no SQL: sem N+1 ou recorte antes de remover contatos inelegíveis.
            $ready = EmailCampaignRecipient::query()
                ->where('email_campaign_id', $source->id)
                ->where('status', 'sent')->whereNull('replied_at')
                ->whereNull('excluded_from_sequences_at')
                ->where('sent_at', '<=', $cutoff)
                ->whereNotIn('id', DB::table('email_campaign_replies')
                    ->whereNotNull('email_campaign_recipient_id')->select('email_campaign_recipient_id'))
                ->whereNotIn('normalized_email', DB::table('email_campaign_replies as r')
                    ->join('email_campaign_recipients as cr', 'cr.id', '=', 'r.email_campaign_recipient_id')
                    ->whereNotNull('cr.normalized_email')->select('cr.normalized_email'))
                ->whereNotIn('normalized_email', DB::table('email_campaign_bounces')->select('final_recipient'))
                ->whereNotIn('normalized_email', DB::table('email_campaign_suppressions')->select('normalized_email'))
                ->orderBy('id')->limit($limit + 1)->get();
            if ($ready->isEmpty()) {
                return null; // Nenhum destinatário elegível; plano segue aguardando.
            }
            if ($ready->count() > $limit) {
                throw ValidationException::withMessages([
                    'sequence' => 'A segunda rodada ultrapassa o limite de '.$limit.' destinatários. Divida o público antes de preparar.',
                ]);
            }
            $draft = EmailCampaign::query()->create([
                'name' => '[FOLLOW-UP] '.mb_substr((string) $plan->name, 0, 150),
                'subject' => (string) $plan->subject,
                'body' => (string) $plan->body,
                'status' => 'draft',
                'from_address' => $source->from_address,
                'from_name' => $source->from_name,
                'reply_to' => $source->reply_to,
                'created_by' => $plan->created_by,
            ]);
            foreach ($ready as $original) {
                $followup = $draft->recipients()->create([
                    'source_key' => $original->source_key,
                    'email' => $original->email,
                    'normalized_email' => $original->normalized_email,
                    'contact_name' => $original->contact_name,
                    'company_name' => $original->company_name,
                    'job_title' => $original->job_title,
                    'status' => 'pending',
                ]);
                DB::table('email_campaign_sequence_links')->insert([
                    'sequence_id' => $sequenceId,
                    'original_recipient_id' => $original->id,
                    'followup_recipient_id' => $followup->id,
                    'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
            DB::table('email_campaign_sequences')->where('id', $sequenceId)->update([
                'draft_campaign_id' => $draft->id, 'status' => 'prepared', 'updated_at' => now('UTC'),
            ]);

            return (int) $draft->id;
        }, 3);
    }
}
