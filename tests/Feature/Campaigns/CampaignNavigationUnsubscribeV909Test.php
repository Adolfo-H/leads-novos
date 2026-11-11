<?php

use App\Livewire\CampaignFollowupEditor;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignSuppression;
use App\Models\User;
use App\Services\Campaigns\UnsubscribeToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function ec909Campaign(): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Teste de navegação V90.9',
        'subject' => 'Uma apresentação comercial',
        'body' => 'Mensagem comercial de teste que não será enviada.',
        'status' => 'completed',
        'from_address' => 'teste@example.invalid',
        'from_name' => 'ExportControl',
        'reply_to' => 'teste@example.invalid',
    ]);
}

function ec909Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('navigates every campaign detail tab by normal GET links without SMTP or Livewire action', function (): void {
    $campaign = ec909Campaign();
    Mail::fake();
    Queue::fake();
    $user = ec909Manager();
    foreach ([
        'recipients' => 'Nenhum destinatário registrado.',
        'bounces' => 'E-mails devolvidos',
        'message' => 'Mensagem salva',
        'replies' => 'Nenhuma resposta identificada pelo IMAP',
    ] as $tab => $expected) {
        $this->actingAs($user)
            ->get(route('campaigns.index', ['campanha' => $campaign->id, 'aba' => $tab]))
            ->assertOk()
            ->assertSee($expected);
    }
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('does not allow a foreign tab name to escape the campaign overview', function (): void {
    $campaign = ec909Campaign();
    $this->actingAs(ec909Manager())
        ->get(route('campaigns.index', ['campanha' => $campaign->id, 'aba' => 'qualquer']))
        ->assertOk()
        ->assertSee('Visão geral do envio');
});

it('renders the new public unsubscribe view but never suppresses someone on GET', function (): void {
    $campaign = ec909Campaign();
    $recipient = $campaign->recipients()->create([
        'email' => 'sem-envio@example.invalid',
        'normalized_email' => 'sem-envio@example.invalid',
        'status' => 'sent',
    ]);
    $token = app(UnsubscribeToken::class)->forRecipient($recipient);
    $this->get(route('campaigns.unsubscribe', ['token' => $token]))
        ->assertOk()
        ->assertSee('Você decide quais e-mails receber.')
        ->assertSee('Privacidade e transparência')
        ->assertSee('Confirmar descadastro');
    expect(EmailCampaignSuppression::query()->count())->toBe(0);
});

it('shows follow-up validation in Portuguese and keeps the incomplete plan unsaved', function (): void {
    $campaign = ec909Campaign();
    $user = ec909Manager();
    Livewire::actingAs($user)
        ->test(CampaignFollowupEditor::class, ['campaignId' => (int) $campaign->id])
        ->set('body', '')
        ->call('saveChanges')
        // EC_V90_10_CI_COMPAT: o componente captura ValidationException; Livewire guarda
        // a mensagem do campo, não necessariamente a identificação interna 'required'.
        ->assertHasErrors('body')
        ->assertSee('Informe a mensagem das próximas rodadas.')
        ->assertSet('saved', false);
    expect(DB::table('email_campaign_sequences')->count())->toBe(0);
});
