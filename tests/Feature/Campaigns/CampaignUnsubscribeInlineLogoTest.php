<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignSuppression;
use App\Services\Campaigns\UnsubscribeToken;

it('incorpora a logo no HTML público sem abrir uma rota de imagens', function (): void {
    $campaign = EmailCampaign::query()->create([
        'name' => 'Teste visual V90.13',
        'subject' => 'Mensagem de teste sem envio',
        'body' => 'Mensagem fictícia usada somente para verificar o descadastro.',
        'status' => 'completed',
        'from_address' => 'teste@example.invalid',
        'from_name' => 'ExportControl',
        'reply_to' => 'teste@example.invalid',
    ]);
    $recipient = $campaign->recipients()->create([
        'email' => 'teste-logo@example.invalid',
        'normalized_email' => 'teste-logo@example.invalid',
        'status' => 'sent',
    ]);
    $token = app(UnsubscribeToken::class)->forRecipient($recipient);

    $this->get(route('campaigns.unsubscribe', ['token' => $token]))
        ->assertOk()
        ->assertSee('data:image/', false)
        ->assertSee(';base64,', false)
        ->assertSee('Confirmar descadastro');

    expect(EmailCampaignSuppression::query()
        ->where('normalized_email', 'teste-logo@example.invalid')->exists())->toBeFalse();
});
