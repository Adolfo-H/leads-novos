<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Salva apenas observação experimental; NUNCA altera a confirmação humana. */
final class WhatsAppWebProbeRecord extends Command
{
    protected $signature = 'contacts:wa-web:record {phone_id : ID local do telefone} {result : found|not_found|inconclusive|error}';

    protected $description = 'Salva resultado experimental da consulta não oficial, sem modificar WhatsApp confirmado/não possui.';

    public function handle(): int
    {
        $id = filter_var((string) $this->argument('phone_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $result = (string) $this->argument('result');
        if ($id === false || ! in_array($result, ['found', 'not_found', 'inconclusive', 'error'], true)) {
            $this->error('Informe ID numérico positivo e resultado válido.');
            return self::FAILURE;
        }

        DB::transaction(static function () use ($id, $result): void {
            $phone = DB::table('whatsapp_phone_numbers')->where('id', $id)
                ->where('contacts_count', '>', 0)->lockForUpdate()->first();
            if ($phone === null || ! in_array($phone->kind, ['mobile', 'landline'], true)
                || ! is_string($phone->e164)
                || preg_match('/^55[1-9][0-9]{9,10}$/D', $phone->e164) !== 1) {
                throw new \InvalidArgumentException('Telefone indisponível para consulta experimental.');
            }

            $timestamp = now('UTC');
            $original = DB::table('whatsapp_web_probe_results')->where('phone_id', $id)->lockForUpdate()->first();
            if ($original === null) {
                DB::table('whatsapp_web_probe_results')->insert([
                    'phone_id' => $id, 'result' => $result, 'provider' => 'baileys_unofficial',
                    'checked_at' => $timestamp, 'attempts' => 1,
                    'created_at' => $timestamp, 'updated_at' => $timestamp,
                ]);
            } else {
                DB::table('whatsapp_web_probe_results')->where('phone_id', $id)->update([
                    'result' => $result, 'provider' => 'baileys_unofficial',
                    'checked_at' => $timestamp, 'attempts' => $original->attempts + 1,
                    'updated_at' => $timestamp,
                ]);
            }
            DB::table('whatsapp_web_probe_events')->insert([
                'phone_id' => $id, 'result' => $result,
                'provider' => 'baileys_unofficial', 'checked_at' => $timestamp,
            ]);
        });

        $this->info('Observação experimental salva. Confirmação manual preservada.');
        return self::SUCCESS;
    }
}
