<?php

namespace App\Http\Controllers;

use App\Services\Campaigns\CampaignManager;
use App\Services\Campaigns\UnsubscribeToken;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CampaignUnsubscribeController extends Controller
{
    public function show(string $token, UnsubscribeToken $tokens): View
    {
        abort_if($tokens->resolve($token) === null, 404);

        return view('campaigns.unsubscribe', ['token' => $token]);
    }

    public function store(Request $request, string $token, UnsubscribeToken $tokens, CampaignManager $manager): RedirectResponse
    {
        $recipient = $tokens->resolve($token);
        abort_if($recipient === null, 404);
        $manager->suppress($recipient->normalized_email);

        return redirect()->route('campaigns.unsubscribe', ['token' => $token])
            ->with('unsubscribed', true);
    }
}
