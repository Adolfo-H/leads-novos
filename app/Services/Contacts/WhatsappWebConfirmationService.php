<?php

namespace App\Services\Contacts;

use Illuminate\Support\Facades\DB;

/** Mantém positivos técnicos no índice global, separados das decisões humanas em Contatos. */
final class WhatsappWebConfirmationService
{
    public function promote(int $phoneId): bool
    {
        return DB::transaction(function () use ($phoneId): bool {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(27041, 917)');
            }

            $phone = DB::table('whatsapp_phone_numbers')->where('id', $phoneId)
                ->where('contacts_count', '>', 0)->lockForUpdate()->first();
            if ($phone === null || $phone->status !== 'unknown' || $phone->kind === 'review') {
                return false;
            }

            $probe = DB::table('whatsapp_web_probe_results')->where('phone_id', $phoneId)->first();
            if ($probe === null || $probe->result !== 'found') {
                return false;
            }

            $manualAfter = DB::table('whatsapp_phone_events')
                ->where('phone_id', $phoneId)
                ->where('created_at', '>=', $probe->checked_at)
                ->whereIn('evidence', [
                    'manual_app_confirmed', 'manual_app_unavailable', 'manual_reset',
                    'directory_manual_check', 'directory_manual_reset', 'legacy_manual',
                ])->exists();
            if ($manualAfter) {
                return false;
            }

            $references = DB::table('whatsapp_phone_sources')->where('phone_id', $phoneId)
                ->select('source_key', 'raw_digits')->distinct()->get();
            foreach ($references->chunk(100) as $batch) {
                $hasHumanDecision = DB::table('contact_phone_verifications')
                    ->whereIn('whatsapp_status', ['yes', 'no'])
                    ->where(function ($query) use ($batch): void {
                        foreach ($batch as $source) {
                            $query->orWhere(function ($pair) use ($source): void {
                                $pair->where('source_key', $source->source_key)
                                    ->where('phone_normalized', $source->raw_digits);
                            });
                        }
                    })->exists();
                if ($hasHumanDecision) {
                    return false;
                }
            }

            $when = now('UTC');
            $updated = DB::table('whatsapp_phone_numbers')->where('id', $phoneId)
                ->where('status', 'unknown')->update([
                    'status' => 'yes',
                    'evidence' => 'web_found_unofficial',
                    'verified_by' => null,
                    'verified_at' => $probe->checked_at,
                    'updated_at' => $when,
                ]);
            if ($updated !== 1) {
                return false;
            }

            DB::table('whatsapp_phone_events')->insert([
                'phone_id' => $phoneId,
                'actor_id' => null,
                'old_status' => 'unknown',
                'new_status' => 'yes',
                'evidence' => 'web_found_unofficial',
                'note' => 'Indício positivo técnico via WhatsApp Web não oficial. Sem mensagem enviada.',
                'created_at' => $when,
            ]);

            return true;
        }, 2);
    }

    /** @return array{found:int,manual_confirmations_written:int} */
    public function reconcile(int $limit = 500): array
    {
        $ids = DB::table('whatsapp_web_probe_results as p')
            ->join('whatsapp_phone_numbers as n', 'n.id', '=', 'p.phone_id')
            ->where('p.result', 'found')
            ->where(function ($query): void {
                $query->where('n.status', 'unknown')
                    ->orWhere('n.evidence', 'web_found_unofficial');
            })
            ->orderBy('p.phone_id')->limit(max(1, min(10000, $limit)))
            ->pluck('p.phone_id');

        return ['found' => $ids->count(), 'manual_confirmations_written' => 0];
    }
}
