<?php

namespace App\Services\Contacts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vínculo explícito de números públicos à empresa escolhida pelo gestor.
 * Não cria contatos, não altera o HubSpot e não afirma que o WhatsApp está ativo.
 */
final class WhatsappCompanyChannelLinkV16
{
    public function assign(User $actor, int $channelId, string $target): void
    {
        $this->assignMany($actor, [$channelId], $target);
    }

    /**
     * Associa uma seleção da MESMA empresa de origem, numa transação única.
     * Evidências duplicadas do mesmo número/origem recebem o mesmo vínculo.
     *
     * @param  list<int|string>  $channelIds
     */
    public function assignMany(User $actor, array $channelIds, string $target): int
    {
        abort_unless($actor->isCommercialManager(), 403);

        if (preg_match('/^(local|hubspot):(\d+)$/D', $target, $match) !== 1) {
            throw ValidationException::withMessages(['wa16_link' => 'Selecione uma empresa válida na lista.']);
        }

        if ($channelIds === [] || count($channelIds) > 50) {
            throw ValidationException::withMessages(['wa16_link' => 'Selecione de 1 a 50 números de uma única empresa de origem.']);
        }

        $ids = [];
        foreach ($channelIds as $value) {
            if (! is_int($value) && ! ctype_digit($value)) {
                throw ValidationException::withMessages(['wa16_link' => 'Seleção de números inválida.']);
            }
            $id = (int) $value;
            if ($id < 1) {
                throw ValidationException::withMessages(['wa16_link' => 'Seleção de números inválida.']);
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));

        $type = $match[1];
        $targetId = (int) $match[2];
        $table = $type === 'local' ? 'companies' : 'hubspot_companies';

        return DB::transaction(static function () use ($actor, $ids, $type, $targetId, $table): int {
            abort_unless(DB::table($table)->where('id', $targetId)->exists(), 404);

            $selected = DB::table('whatsapp_public_company_channels')
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'e164', 'hubspot_company_id']);

            if ($selected->count() !== count($ids)) {
                throw ValidationException::withMessages(['wa16_link' => 'Um número não está mais disponível. Atualize a busca.']);
            }

            if ($selected->pluck('hubspot_company_id')->unique()->count() !== 1) {
                throw ValidationException::withMessages(['wa16_link' => 'Selecione números da mesma empresa de origem. Vincule as demais empresas separadamente.']);
            }

            // Uma única consulta para os IDs de todas as evidências do mesmo
            // número e empresa. Não existe N+1 de consultas por telefone.
            $all = DB::table('whatsapp_public_company_channels')
                ->where('hubspot_company_id', $selected->first()->hubspot_company_id)
                ->whereIn('e164', $selected->pluck('e164')->unique()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            if (DB::table('whatsapp_company_channel_links_v16')
                ->whereIn('channel_id', $all->pluck('id')->all())->exists()) {
                throw ValidationException::withMessages(['wa16_link' => 'Um dos números já foi vinculado. Atualize a lista antes de confirmar. Nenhum vínculo foi modificado.']);
            }

            $stamp = now('UTC');
            $rows = $all->map(static fn (object $row): array => [
                'channel_id' => (int) $row->id,
                'target_type' => $type,
                'target_id' => $targetId,
                'linked_by' => $actor->id,
                'linked_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ])->all();

            DB::table('whatsapp_company_channel_links_v16')->insert($rows);

            return $selected->pluck('e164')->unique()->count();
        }, 3);
    }
}
