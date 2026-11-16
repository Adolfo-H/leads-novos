<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Campaigns\CampaignContactsSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Recebe a seleção pelo POST nativo para não depender da navegação Livewire. */
final class CampaignContactsSelectionController extends Controller
{
    public function __invoke(Request $request, CampaignContactsSelection $transfer): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);
        $limit = max(1, min(1000, (int) config('campaign_mail.max_recipients', 100)));
        /** @var array{source_keys:list<string>,source_emails:array<string,string>} $validated */
        $validated = $request->validate([
            'source_keys' => ['required', 'array', 'min:1', 'max:'.$limit],
            'source_keys.*' => ['required', 'string', 'max:160', 'regex:/^(?:(?:hubspot|manual):[0-9]+|receita:[0-9a-fA-F-]{36})$/'],
            'source_emails' => ['required', 'array', 'max:'.$limit],
            'source_emails.*' => ['nullable', 'string', 'max:255'],
        ]);

        $selection = $transfer->validateExact($validated['source_keys'], $validated['source_emails']);
        $wizardId = (string) Str::uuid();
        $request->session()->put('campaign_transfer_pending_v92_'.$wizardId, $selection['emails']);

        return redirect()->route('campaigns.wizard-v93', ['wizard_id' => $wizardId]);
    }
}
