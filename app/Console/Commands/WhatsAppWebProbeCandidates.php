<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Saída JSON para um processo local autenticado por QR no WhatsApp Web.
 * Somente candidatos sem confirmação manual e com formato E.164 confiável.
 * Nunca executa consultas na rede nem altera telefones.
 */
final class WhatsAppWebProbeCandidates extends Command
{
    protected $signature = 'contacts:wa-web:candidates {--limit=5 : Máximo de 10 por execução} {--include-fixed : Incluir fixos de formato válido} {--fixed-only : Apenas telefones fixos válidos} {--slot= : Partição exclusiva 0 ou 1 (duas sessões)} {--ids= : IDs explícitos do gestor, no máximo dez}';

    protected $description = 'Lista até 10 telefones candidatos à conferência experimental pelo WhatsApp Web (JSON, leitura).';

    public function handle(): int
    {
        $rawLimit = $this->option('limit');
        if (! is_scalar($rawLimit) || ! preg_match('/^\d+$/D', (string) $rawLimit)
            || (int) $rawLimit < 1 || (int) $rawLimit > 10) {
            $this->error('--limit deve ser de 1 a 10.');

            return self::FAILURE;
        }

        $limit = (int) $rawLimit;
        // EC_WA_V16_CANDIDATES: IDs vindo do lote aprovado pelo gestor, nunca de texto livre.
        $rawIds = trim((string) ($this->option('ids') ?? ''));
        $ids = [];
        if ($rawIds !== '') {
            if (! preg_match('/^\d+(?:,\d+){0,9}$/D', $rawIds)) {
                $this->error('--ids inválidos.');

                return self::FAILURE;
            }
            $ids = array_values(array_unique(array_map('intval', explode(',', $rawIds))));
            if (in_array(0, $ids, true)) {
                return self::FAILURE;
            }
        }
        $slotOption = $this->option('slot');
        $slot = $slotOption === null || $slotOption === '' ? null : (string) $slotOption;
        if ($slot !== null && ! in_array($slot, ['0', '1'], true)) {
            $this->error('--slot deve ser 0 ou 1 (ou omitido para o modo legado).');

            return self::FAILURE;
        }
        $kinds = $this->option('fixed-only') ? ['landline'] : ($this->option('include-fixed') ? ['mobile', 'landline'] : ['mobile']); // EC_WA_FULL_DIRECTORY_V8_CANDIDATES
        $olderThan = now('UTC')->subDays(30);

        $rows = DB::table('whatsapp_phone_numbers as n')
            ->leftJoin('whatsapp_web_probe_results as probe', 'probe.phone_id', '=', 'n.id')
            ->whereIn('n.kind', $kinds)
            ->where('n.status', 'unknown')
            ->where('n.contacts_count', '>', 0)
            ->whereNotNull('n.e164');
        if ($ids !== []) {
            $rows->whereIn('n.id', $ids);
        }

        // Partição fixa e estável, aplicada no PostgreSQL/SQLite ANTES do LIMIT.
        // Um mesmo phone_id nunca é listado simultaneamente nas sessões 1 e 2.
        if ($slot !== null) {
            $rows->whereRaw('(n.id % 2) = ?', [(int) $slot]);
        }

        $candidates = $rows
            ->where(function ($query) use ($olderThan, $ids): void {
                $cutoff = $ids !== [] ? now('UTC')->subDay() : $olderThan;
                $query->whereNull('probe.phone_id')->orWhere('probe.checked_at', '<', $cutoff);
            })
            ->orderBy('n.id')
            ->limit($limit * 3) // sobra para rejeitar localmente entradas ruins
            ->get(['n.id', 'n.e164', 'n.kind'])
            ->filter(static fn (object $n): bool => preg_match('/^55[1-9][0-9]{9,10}$/D', (string) $n->e164) === 1)
            ->take($limit)
            ->map(static fn (object $n): array => [
                'id' => (int) $n->id,
                'phone' => (string) $n->e164,
                'kind' => (string) $n->kind,
            ])->values()->all();

        $this->output->writeln(json_encode([
            'count' => count($candidates),
            'candidates' => $candidates,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
