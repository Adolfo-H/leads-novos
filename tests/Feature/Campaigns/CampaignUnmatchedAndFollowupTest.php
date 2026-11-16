<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignBounce;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignSuppression;
use App\Models\User;
use App\Services\Campaigns\CampaignInbox;
use App\Services\Campaigns\CampaignSequencePlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.followup_drafts_enabled', false);
    Mail::fake();
    Queue::fake();
});

function ec294Manager(): User
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

function ec294Campaign(string $status = 'completed'): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Primeira rodada',
        'subject' => 'Apresentação',
        'body' => 'Mensagem original que já foi enviada.',
        'status' => $status,
        'from_address' => 'comercial@example.org',
        'from_name' => 'ExportControl',
        'reply_to' => 'comercial@example.org',
    ]);
}

function ec294Recipient(EmailCampaign $campaign, string $email, int $daysAgo = 10): EmailCampaignRecipient
{
    return $campaign->recipients()->create([
        'email' => $email,
        'normalized_email' => $email,
        'status' => 'sent',
        'attempted_at' => now('UTC')->subDays($daysAgo),
        'sent_at' => now('UTC')->subDays($daysAgo),
        'message_id' => 'tracking-'.str_replace('@', '-', $email).'@example.org',
    ]);
}

it('registra DSNs não vinculados sem criar bloqueios nem associações incorretas', function (): void {
    $mailbox = 'box@example.org';
    $campaign = ec294Campaign();
    $sent = ec294Recipient($campaign, 'real@example.org');
    $known = [
        'original_message_id' => $sent->message_id,
        'final_recipient' => 'real@example.org',
        'status_code' => '5.1.1',
        'action' => 'failed',
        'classification' => 'invalid',
        'diagnostic' => 'smtp; user unknown',
    ];
    $unknown = [
        'original_message_id' => 'externo@example.org',
        'final_recipient' => 'alguem@example.org',
        'status_code' => '5.0.0',
        'action' => 'failed',
        'classification' => 'permanent_other',
        'diagnostic' => 'detalhe privado, não armazenar',
    ];
    $batch = [
        'uidvalidity' => '900', 'last_uid' => 11, 'initialized' => false,
        'messages' => [
            ['uid' => 10, 'bounce' => $known],
            ['uid' => 11, 'bounce' => $unknown],
        ],
    ];

    expect(app(CampaignInbox::class)->import($mailbox, $batch))->toBe(0);
    expect(DB::table('email_campaign_unmatched_bounces')->count())->toBe(1);
    expect(EmailCampaignBounce::query()->count())->toBe(1);
    expect(DB::table('email_campaign_unmatched_bounces')->first()->recipient_mask)->toBe('a***@example.org');
    expect(DB::table('email_campaign_unmatched_bounces')->first()->status_code)->toBe('5.0.0');
    expect(EmailCampaignSuppression::query()->where('normalized_email', 'alguem@example.org')->exists())->toBeFalse();
    app(CampaignInbox::class)->import($mailbox, $batch);
    expect(DB::table('email_campaign_unmatched_bounces')->count())->toBe(1);

    $manager = ec294Manager();
    $this->actingAs($manager)->get(route('campaigns.queue'))
        ->assertOk()->assertSee('Devoluções sem vínculo com campanhas')
        ->assertSee('a***@example.org')->assertDontSee('alguem@example.org');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('planeja e prepara a segunda rodada sem enviar; elimina resposta bounce e descadastro', function (): void {
    $manager = ec294Manager();
    $source = ec294Campaign();
    $valid = ec294Recipient($source, 'valido@example.org');
    $replied = ec294Recipient($source, 'respondeu@example.org');
    $replied->update(['replied_at' => now('UTC')]);
    $bounced = ec294Recipient($source, 'devolvido@example.org');
    EmailCampaignBounce::query()->create([
        'email_campaign_id' => $source->id,
        'email_campaign_recipient_id' => $bounced->id,
        'mailbox' => 'box@example.org',
        'uid_validity' => '950',
        'imap_uid' => 18,
        'final_recipient' => 'devolvido@example.org',
        'status_code' => '4.2.2',
        'action' => 'delayed',
        'classification' => 'temporary',
        'received_at' => now('UTC'),
    ]);
    ec294Recipient($source, 'descadastro@example.org');
    EmailCampaignSuppression::query()->create(['normalized_email' => 'descadastro@example.org', 'reason' => 'unsubscribe']);

    config()->set('campaign_mail.live_enabled', true);
    Livewire::actingAs($manager)->test('pages::campaigns.index')
        ->call('openCampaign', $source->id)
        ->set('followUpDays', 5)
        ->set('followUpName', 'Retomada da apresentação')
        ->set('followUpSubject', 'Podemos retomar a conversa?')
        ->set('followUpBody', 'Olá, gostaria de retomar nossa conversa sobre o sistema.')
        ->call('planFollowUp')
        ->assertHasNoErrors();

    $plan = DB::table('email_campaign_sequences')->where('email_campaign_id', $source->id)->first();
    expect($plan)->not->toBeNull();
    expect((bool) $plan->auto_prepare)->toBeTrue();
    expect($plan->status)->toBe('prepared');
    $draft = EmailCampaign::query()->findOrFail((int) $plan->draft_campaign_id);
    expect($draft->status)->toBe('draft');
    expect($draft->recipients()->count())->toBe(1);
    expect($draft->recipients()->firstOrFail()->normalized_email)->toBe($valid->normalized_email);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('prepara automaticamente somente rascunho após o último envio cumprir o prazo', function (): void {
    $manager = ec294Manager();
    $source = ec294Campaign();
    $old = ec294Recipient($source, 'antigo@example.org', 10);
    $new = ec294Recipient($source, 'recente@example.org', 1);
    $planner = app(CampaignSequencePlanner::class);
    $id = $planner->create($manager, $source, 'Retomada', 5, 'Assunto diferente', 'Mensagem nova para os contatos.');
    DB::table('email_campaign_sequences')->where('id', $id)->update(['auto_prepare' => true]);

    expect($planner->prepare($manager, $id))->toBeNull();
    expect(EmailCampaign::query()->where('name', '[FOLLOW-UP] Retomada')->count())->toBe(0);
    $new->update(['sent_at' => now('UTC')->subDays(8)]);
    $this->artisan('campaigns:prepare-followup-drafts')->assertExitCode(0);
    $plan = DB::table('email_campaign_sequences')->where('id', $id)->first();
    expect($plan->status)->toBe('prepared');
    expect(EmailCampaign::query()->findOrFail((int) $plan->draft_campaign_id)->recipients()->count())->toBe(2);
    $this->artisan('campaigns:prepare-followup-drafts')->assertExitCode(0);
    expect(EmailCampaign::query()->where('name', '[FOLLOW-UP] Retomada')->count())->toBe(1);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('não omite destinatários silenciosamente quando a segunda rodada ultrapassa o limite', function (): void {
    $manager = ec294Manager();
    $source = ec294Campaign();
    ec294Recipient($source, 'a@example.org');
    ec294Recipient($source, 'b@example.org');
    config()->set('campaign_mail.max_recipients', 1);
    $planner = app(CampaignSequencePlanner::class);
    $id = $planner->create($manager, $source, 'Retomada', 5, 'Novo assunto', 'Um novo texto comercial completo.');
    expect(fn () => $planner->prepare($manager, $id))->toThrow(ValidationException::class);
    expect(DB::table('email_campaign_sequences')->where('id', $id)->value('status'))->toBe('planned');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
