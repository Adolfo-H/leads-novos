<?php

use App\Models\EmailCampaign;
use App\Services\Campaigns\UnsubscribeToken;

it('renderiza a logo do descadastro com blend para esconder fundo branco sem criar descadastro no GET', function (): void {
    $campaign = EmailCampaign::query()->create([
        'name' => 'Teste visual V90.14',
        'subject' => 'Mensagem de teste sem envio',
        'body' => 'Mensagem fictícia usada somente para verificar o descadastro.',
        'status' => 'completed',
        'from_address' => 'teste@example.invalid',
        'from_name' => 'ExportControl',
        'reply_to' => 'teste@example.invalid',
    ]);

    $recipient = $campaign->recipients()->create([
        'email' => 'teste-logo-blend@example.invalid',
        'normalized_email' => 'teste-logo-blend@example.invalid',
        'status' => 'sent',
    ]);

    $token = app(UnsubscribeToken::class)->forRecipient($recipient);

    $this->get(route('campaigns.unsubscribe', ['token' => $token]))
        ->assertOk()
        ->assertSee('mix-blend-mode:multiply', false)
        ->assertSee('Confirmar descadastro');
});
