<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignContactTransfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/** POST nativo e protegido: continua funcionando sem JavaScript/Livewire. */
final class CampaignContactsTransferController extends Controller
{
    public function store(Request $request, CampaignContactTransfer $transfer): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isCommercialManager(), 403);

        $limit = max(1, min(1000, (int) config('campaign_mail.max_recipients', 100)));
        /** @var array{campaign_transfer_token:string,campaign_contacts_v92:list<string>,campaign_emails_v92:array<string,string>} $data */
        $data = $request->validate([
            'campaign_transfer_token' => ['required', 'uuid'],
            'campaign_contacts_v92' => ['required', 'array', 'min:1', 'max:'.$limit],
            'campaign_contacts_v92.*' => ['required', 'string', 'max:160', 'regex:/^(?:(?:hubspot|manual):[0-9]+|receita:[0-9a-fA-F-]{36})$/'],
            'campaign_emails_v92' => ['required', 'array', 'min:1', 'max:'.$limit],
            'campaign_emails_v92.*' => ['nullable', 'string', 'max:255'],
        ]);

        $keys = $data['campaign_contacts_v92'];
        $snapshots = $data['campaign_emails_v92'];
        $selectionSessionKey = 'campaign_contact_transfer_v92_'.$data['campaign_transfer_token'];
        $serverSnapshots = $request->session()->get($selectionSessionKey);
        if (! is_array($serverSnapshots)
            || count($keys) !== count($snapshots)
            || count($keys) !== count($serverSnapshots)
            || array_diff($keys, array_keys($snapshots)) !== []
            || array_diff($keys, array_keys($serverSnapshots)) !== []
            || array_diff(array_keys($snapshots), $keys) !== []) {
            throw ValidationException::withMessages([
                'recipients' => 'A seleção expirou ou foi alterada. Marque os contatos novamente.',
            ]);
        }

        foreach ($keys as $key) {
            $postedEmail = $snapshots[$key] ?? null;
            $serverEmail = $serverSnapshots[$key] ?? null;
            if (! is_string($postedEmail) || ! is_string($serverEmail)
                || ! hash_equals($serverEmail, CampaignAudience::normalizedEmail($postedEmail))) {
                throw ValidationException::withMessages([
                    'recipients' => 'Os e-mails da seleção não correspondem à confirmação exibida. Marque os contatos novamente.',
                ]);
            }
        }

        $expected = $transfer->capture($keys, $serverSnapshots);
        $request->session()->forget($selectionSessionKey);
        $wizardId = strtolower($data['campaign_transfer_token']);
        $request->session()->put('campaign_transfer_pending_v92_'.$wizardId, $expected);

        // A rota GET apenas abre o assistente; não cria rascunho e não envia mensagens.
        return redirect()->route('campaigns.wizard-v93', ['wizard_id' => $wizardId]);
    }
}
