<?php

use App\Livewire\CampaignFollowupEditor;
use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupSettings;
use App\Services\Campaigns\CampaignFollowupTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/** @return array{0:User,1:EmailCampaign} */
function makeFollowupV5Fixture(): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $root = EmailCampaign::query()->create([
        'name' => 'primeira campanha',
        'subject' => 'Contato comercial ExportControl',
        'body' => 'Apresentação das funcionalidades da ExportControl para o cliente.',
        'from_address' => 'comercial@exportcontrol.com.br',
        'from_name' => 'ExportControl',
        'reply_to' => 'comercial@exportcontrol.com.br',
        'status' => 'completed',
        'completed_at' => now('UTC')->subDays(1),
        'created_by' => $user->id,
    ]);
    $root->recipients()->create([
        'email' => 'contato@empresa.example',
        'normalized_email' => 'contato@empresa.example',
        'status' => 'sent',
        'sent_at' => now('UTC')->subDays(1),
    ]);

    return [$user, $root];
}

/** @return array{name:string,delay_days:int,max_rounds:int,subject:string,body:string} */
function followupV5Values(): array
{
    return [
        'name' => 'Retomada comercial',
        'delay_days' => 5,
        'max_rounds' => 2,
        'subject' => 'RE: Contato comercial ExportControl',
        'body' => 'Bom dia, gostaria de retomar a conversa anterior sobre a ExportControl.',
    ];
}

it('converts a manually planned followup into automatic, without creating or sending campaigns', function (): void {
    [$user, $root] = makeFollowupV5Fixture();
    $values = followupV5Values();
    $planId = (int) DB::table('email_campaign_sequences')->insertGetId([
        'email_campaign_id' => $root->id,
        'name' => $values['name'],
        'delay_days' => 5,
        'max_rounds' => 1,
        'subject' => $values['subject'],
        'body' => $values['body'],
        'automatic' => false,
        'status' => 'planned',
        'created_by' => $user->id,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
    $service = app(CampaignFollowupSettings::class);
    expect($service->activateExisting($user, (int) $root->id, $planId, 2))->toBe($planId);
    $plan = DB::table('email_campaign_sequences')->find($planId);
    expect((bool) $plan->automatic)->toBeTrue()
        ->and($plan->status)->toBe('active')
        ->and((int) $plan->max_rounds)->toBe(2)
        ->and((int) $plan->last_campaign_id)->toBe((int) $root->id)
        ->and(EmailCampaign::query()->count())->toBe(1)
        ->and(DB::table('email_campaign_sequence_links')->count())->toBe(0);
    $service->activateExisting($user, (int) $root->id, $planId, 2);
    expect(DB::table('email_campaign_sequences')->count())->toBe(1);
});

it('updates the next email and interval in place, without duplicates', function (): void {
    [$user, $root] = makeFollowupV5Fixture();
    $service = app(CampaignFollowupSettings::class);
    $id = $service->save($user, (int) $root->id, followupV5Values());
    $changed = followupV5Values();
    $changed['delay_days'] = 3;
    $changed['subject'] = 'Novo assunto para o follow-up';
    $changed['body'] = 'Um texto atualizado para o próximo contato comercial.';
    expect($service->save($user, (int) $root->id, $changed))->toBe($id);
    $plan = DB::table('email_campaign_sequences')->find($id);
    expect((int) $plan->delay_days)->toBe(3)
        ->and($plan->subject)->toBe('Novo assunto para o follow-up')
        ->and($plan->body)->toBe($changed['body'])
        ->and(DB::table('email_campaign_sequences')->count())->toBe(1)
        ->and(EmailCampaign::query()->count())->toBe(1);
});

it('pauses only future automatic rounds', function (): void {
    [$user, $root] = makeFollowupV5Fixture();
    $service = app(CampaignFollowupSettings::class);
    $id = $service->save($user, (int) $root->id, followupV5Values());
    $service->changePause($user, (int) $root->id, $id, true);
    expect(DB::table('email_campaign_sequences')->where('id', $id)->value('status'))->toBe('paused');
    $service->changePause($user, (int) $root->id, $id, false);
    expect(DB::table('email_campaign_sequences')->where('id', $id)->value('status'))->toBe('active');
});

it('does not create automatic followups for campaigns that have not completed', function (): void {
    [$user, $root] = makeFollowupV5Fixture();
    $root->update(['status' => 'running', 'completed_at' => null]);
    expect(fn () => app(CampaignFollowupSettings::class)
        ->save($user, (int) $root->id, followupV5Values()))->toThrow(ValidationException::class);
    expect(DB::table('email_campaign_sequences')->count())->toBe(0);
});

it('does not allow a seller to modify automatic followups', function (): void {
    [$user, $root] = makeFollowupV5Fixture();
    $user->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    expect(fn () => app(CampaignFollowupSettings::class)
        ->save($user, (int) $root->id, followupV5Values()))->toThrow(HttpException::class);
});

it('renders a configurable panel for the commercial manager', function (): void {
    [$user, $root] = makeFollowupV5Fixture();
    app(CampaignFollowupSettings::class)->save($user, (int) $root->id, followupV5Values());
    Livewire::actingAs($user)->test(CampaignFollowupEditor::class, ['campaignId' => (int) $root->id])
        ->assertSee('Próximos e-mails, no automático')
        ->assertSee('Retomada comercial')
        ->assertSee('RE: Contato comercial ExportControl')
        ->assertSee('Ajustar os próximos envios');
});

it('allows planning followups in a saved draft without sending or creating another campaign', function (): void {
    [$user, $root] = makeFollowupV5Fixture();
    $root->forceFill(['status' => 'draft', 'completed_at' => null])->save();
    $root->recipients()->update(['status' => 'pending', 'sent_at' => null]);

    $service = app(CampaignFollowupSettings::class);
    $id = $service->save($user, (int) $root->id, followupV5Values());
    $plan = DB::table('email_campaign_sequences')->find($id);

    expect((bool) $plan->automatic)->toBeTrue()
        ->and($plan->status)->toBe('active')
        ->and((int) $plan->last_campaign_id)->toBe((int) $root->id)
        ->and(EmailCampaign::query()->count())->toBe(1)
        ->and(DB::table('email_campaign_sequence_links')->count())->toBe(0);

    $updated = followupV5Values();
    $updated['delay_days'] = 7;
    expect($service->save($user, (int) $root->id, $updated))->toBe($id);
    expect(DB::table('email_campaign_sequences')->count())->toBe(1);

    $timeline = app(CampaignFollowupTimeline::class)->forCampaign($root->fresh());
    expect($timeline['state'])->toBe('waiting')
        ->and($timeline['state_label'])->toBe('Planejado antes do primeiro envio');
});

it('rejects preplanned followups for drafts without pending recipients', function (): void {
    [$user, $root] = makeFollowupV5Fixture();
    $root->forceFill(['status' => 'draft', 'completed_at' => null])->save();

    expect(fn () => app(CampaignFollowupSettings::class)
        ->save($user, (int) $root->id, followupV5Values()))->toThrow(ValidationException::class);
    expect(DB::table('email_campaign_sequences')->count())->toBe(0);
});

it('shows the planning editor while the initial campaign is still a draft', function (): void {
    [$user, $root] = makeFollowupV5Fixture();
    $root->forceFill(['status' => 'draft', 'completed_at' => null])->save();
    $root->recipients()->update(['status' => 'pending', 'sent_at' => null]);

    Livewire::actingAs($user)->test(CampaignFollowupEditor::class, ['campaignId' => (int) $root->id])
        ->assertSee('Planejar próximos e-mails antes do envio')
        ->set('body', 'Quero agendar um próximo contato após a campanha inicial.')
        ->call('saveChanges')
        ->assertSee('Nada será enviado enquanto a primeira campanha estiver em rascunho');

    expect(DB::table('email_campaign_sequences')->where('email_campaign_id', $root->id)->count())->toBe(1);
});

it('sends a native HTTP test only to the manager configured inbox, not campaign recipients', function (): void {
    [$user, $campaign] = makeFollowupV5Fixture();
    $campaign->forceFill(['status' => 'draft', 'completed_at' => null])->save();
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.test_to', 'gestor-test@example.com');
    config()->set('campaign_mail.mailer', 'smtp');
    config()->set('mail.mailers.smtp.transport', 'smtp');
    config()->set('mail.mailers.smtp.host', 'smtp.example.com');
    Mail::fake();
    RateLimiter::clear('campaigns-test:'.$user->id);

    $this->actingAs($user)->post(route('campaigns.email-test', ['campaign' => $campaign->id]), [
        'aba' => 'recipients',
    ])->assertRedirect(route('campaigns.index', [
        'campanha' => $campaign->id, 'aba' => 'recipients',
    ], false).'#email-teste')->assertSessionHas('campaigns_test_ok');

    Mail::assertSent(ProspectorCampaignMessage::class, function ($message): bool {
        return $message->hasTo('gestor-test@example.com')
            && ! $message->hasTo('contato@empresa.example');
    });
    expect($campaign->fresh()->status)->toBe('draft');
});

it('blocks the HTTP test when campaign SMTP is disabled', function (): void {
    [$user, $campaign] = makeFollowupV5Fixture();
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.test_to', 'gestor-test@example.com');
    config()->set('campaign_mail.mailer', 'smtp');
    config()->set('mail.mailers.smtp.transport', 'smtp');
    config()->set('mail.mailers.smtp.host', 'smtp.example.com');
    Mail::fake();

    $this->actingAs($user)->post(route('campaigns.email-test', ['campaign' => $campaign->id]))
        ->assertSessionHas('campaigns_test_error');
    Mail::assertNothingSent();
});
