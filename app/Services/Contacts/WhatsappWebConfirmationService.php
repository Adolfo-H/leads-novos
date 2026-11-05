<?php

namespace App\Services\Contacts;

use Illuminate\Support\Facades\DB;

/**
 * Resultado positivo do WhatsApp Web como confirmação TÉCNICA, distinta da manual.
 * Nunca transforma respostas negativas/inconclusivas em "não possui".
 * Preserva um "não" manual e decisões manuais posteriores à consulta.
 */
final class WhatsappWebConfirmationService
{
    public function promote(int $phoneId): bool
    {
        $changed = DB::transaction(function () use ($phoneId): bool {
            // Mesmo lock da indexação diária e do editor de Contatos.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(27041, 917)');
            }
            $phone = DB::table('whatsapp_phone_numbers')
                ->where('id', $phoneId)->where('contacts_count', '>', 0)
                ->lockForUpdate()->first();
            if ($phone === null || $phone->status !== 'unknown' || $phone->kind === 'review') {
                return false;
            }
            $probe = DB::table('whatsapp_web_probe_results')->where('phone_id', $phoneId)->first();
            if ($probe === null || $probe->result !== 'found') {
                return false;
            }

            // Uma decisão humana registrada APÓS a consulta prevalece, inclusive reset manual.
            $manualAfter = DB::table('whatsapp_phone_events')
                ->where('phone_id', $phoneId)
                ->where('created_at', '>', $probe->checked_at)
                ->whereIn('evidence', [
                    'manual_app_confirmed', 'manual_app_unavailable', 'manual_reset',
                    'directory_manual_check', 'directory_manual_reset', 'legacy_manual',
                ])->exists();
            if ($manualAfter) {
                return false;
            }

            $references = DB::table('whatsapp_phone_sources')->where('phone_id', $phoneId)
                ->select('source_key', 'raw_digits')->distinct()->get();
            // Nunca contradizer um "não possui" humano legado ainda presente em Contatos.
            foreach ($references->chunk(100) as $batch) {
                $hasHumanNo = DB::table('contact_phone_verifications')
                    ->where('whatsapp_status', 'no')
                    ->where(function ($query) use ($batch): void {
                        foreach ($batch as $source) {
                            $query->orWhere(function ($pair) use ($source): void {
                                $pair->where('source_key', $source->source_key)
                                    ->where('phone_normalized', $source->raw_digits);
                            });
                        }
                    })->exists();
                if ($hasHumanNo) {
                    return false;
                }
            }

            $when = now('UTC');
            DB::table('whatsapp_phone_numbers')->where('id', $phoneId)->where('status', 'unknown')->update([
                'status' => 'yes',
                'evidence' => 'web_found_unofficial',
                'verified_by' => null,
                'verified_at' => $probe->checked_at,
                'updated_at' => $when,
            ]);
            DB::table('whatsapp_phone_events')->insert([
                'phone_id' => $phoneId,
                'actor_id' => null,
                'old_status' => 'unknown',
                'new_status' => 'yes',
                'evidence' => 'web_found_unofficial',
                'note' => 'Confirmação técnica via WhatsApp Web não oficial (indício positivo). Sem mensagem enviada.',
                'created_at' => $when,
            ]);

            // Espelhar no mesmo formato usado pela Central de Contatos; as decisões
            // humanas já existentes NÃO são sobrescritas por esta rotina.
            foreach ($references->chunk(300) as $batch) {
                $rows = [];
                foreach ($batch as $ref) {
                    $rows[$ref->source_key.'|'.$ref->raw_digits] = [
                        'source_key' => $ref->source_key,
                        'phone_normalized' => $ref->raw_digits,
                        'whatsapp_status' => 'yes',
                        'verified_by' => null,
                        'verified_at' => $probe->checked_at,
                        'created_at' => $when,
                        'updated_at' => $when,
                    ];
                }
                if ($rows !== []) {
                    DB::table('contact_phone_verifications')->insertOrIgnore(array_values($rows));
                    // Valores legados "unknown" podem ser promovidos, jamais "no".
                    DB::table('contact_phone_verifications')
                        ->where('whatsapp_status', 'unknown')
                        ->where(function ($query) use ($batch): void {
                            foreach ($batch as $source) {
                                $query->orWhere(function ($pair) use ($source): void {
                                    $pair->where('source_key', $source->source_key)
                                        ->where('phone_normalized', $source->raw_digits);
                                });
                            }
                        })->update([
                            'whatsapp_status' => 'yes', 'verified_by' => null,
                            'verified_at' => $probe->checked_at, 'updated_at' => $when,
                        ]);
                }
            }

            return true;
        }, 2);

        if ($changed) {
            app(ContactPeopleDirectory::class)->clearCachedTotals();
        }

        return $changed;
    }

    /** @return array{found:int,promoted:int,skipped:int} */
    public function reconcile(int $limit = 500): array
    {
        $ids = DB::table('whatsapp_web_probe_results as p')
            ->join('whatsapp_phone_numbers as n', 'n.id', '=', 'p.phone_id')
            ->where('p.result', 'found')->where('n.status', 'unknown')
            ->where('n.contacts_count', '>', 0)
            ->orderBy('p.phone_id')->limit(max(1, min(10000, $limit)))
            ->pluck('p.phone_id');
        $promoted = 0;
        foreach ($ids as $id) {
            if ($this->promote((int) $id)) {
                $promoted++;
            }
        }

        return ['found' => $ids->count(), 'promoted' => $promoted, 'skipped' => $ids->count() - $promoted];
    }
}
