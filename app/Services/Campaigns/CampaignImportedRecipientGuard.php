<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** A draft or follow-up cannot turn a missing import approval into an ordinary address. */
final class CampaignImportedRecipientGuard
{
    public function assertReviewed(EmailCampaign $campaign): void
    {
        $rows = $campaign->recipients()->where('status', 'pending')
            ->where('source_key', 'like', 'import:%')->get(['source_key', 'normalized_email']);
        if ($rows->isEmpty()) {
            return;
        }
        if (! Schema::hasTable('email_campaign_import_contacts')) {
            $this->fail();
        }
        foreach ($rows->chunk(500) as $chunk) {
            $ids = [];
            foreach ($chunk as $row) {
                $key = (string) $row->source_key;
                if (preg_match('/^import:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $key) !== 1) {
                    $this->fail();
                }
                $ids[] = substr($key, 7);
            }
            $reviewed = DB::table('email_campaign_import_contacts')->whereIn('id', $ids)
                ->whereNotNull('owner_id')->whereNotNull('reviewed_at')->whereNotNull('permission_reference')->pluck('email', 'id');
            foreach ($chunk as $row) {
                if ($reviewed->get(substr((string) $row->source_key, 7)) !== (string) $row->normalized_email) {
                    $this->fail();
                }
            }
        }
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['campaign' => 'Há contatos importados sem autorização registrada ou com endereço alterado. Revise antes de iniciar.']);
    }
}
