<?php

namespace App\Services\Contacts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Associação explícita decidida pelo gestor, sem alterar o vínculo de origem ou o HubSpot. */
final class WhatsappCompanyChannelLinkV16
{
    public function assign(User $actor, int $channelId, string $target): void
    {
        abort_unless($actor->isCommercialManager(), 403);
        if (preg_match('/^(local|hubspot):(\d+)$/D', $target, $match) !== 1) {
            throw ValidationException::withMessages(['wa16_link' => 'Selecione uma empresa válida na lista.']);
        }
        $type = $match[1];
        $id = (int) $match[2];
        $table = $type === 'local' ? 'companies' : 'hubspot_companies';
        abort_unless(DB::table($table)->where('id', $id)->exists(), 404);
        abort_unless(DB::table('whatsapp_public_company_channels')->where('id', $channelId)->exists(), 404);
        DB::transaction(function () use ($actor, $channelId, $type, $id): void {
            $channel = DB::table('whatsapp_public_company_channels')->where('id', $channelId)->lockForUpdate()->first();
            abort_unless($channel !== null, 404);
            // Todas as evidências do MESMO telefone e empresa de origem recebem o
            // mesmo destino. Evita manter canais duplicados na lista de pendências.
            $channels = DB::table('whatsapp_public_company_channels')
                ->where('e164', $channel->e164)
                ->where('hubspot_company_id', $channel->hubspot_company_id)
                ->pluck('id');
            $stamp = now('UTC');
            $rows = [];
            foreach ($channels as $idChannel) {
                $rows[] = [
                    'channel_id' => (int) $idChannel, 'target_type' => $type, 'target_id' => $id,
                    'linked_by' => $actor->id, 'linked_at' => $stamp,
                    'created_at' => $stamp, 'updated_at' => $stamp,
                ];
            }
            DB::table('whatsapp_company_channel_links_v16')->upsert($rows, ['channel_id'],
                ['target_type', 'target_id', 'linked_by', 'linked_at', 'updated_at']);
        });
    }
}
