<?php

use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignSuppression;
use App\Models\User;
use App\Services\Campaigns\CampaignProductionReadiness;
use App\Services\Campaigns\UnsubscribeToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();
});

it('shows readiness only to managers and never sends emails', function (): void {
    $manager = tap(User::factory()->create(['email_verified_at' => now()]), function (User $u): void {
        $u->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    });
    $this->actingAs($manager)->get(route('campaigns.production.index'))->assertOk();
    $this->get(route('campaigns.production.index'))->assertOk();
    $seller = tap(User::factory()->create(['email_verified_at' => now()]), function (User $u): void {
        $u->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    });
    $this->actingAs($seller)->get(route('campaigns.production.index'))->assertOk();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('records provider limits but never activates live mode or send policy', function (): void {
    $manager = tap(User::factory()->create(['email_verified_at' => now()]), function (User $u): void {
        $u->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    });
    $this->actingAs($manager)->post(route('campaigns.production.save'), [
        'smtp_per_hour' => 25, 'smtp_per_day' => 100,
        'smtp_evidence' => 'Limites informados pelo administrador em chamado interno.',
        'smtp_confirmed' => 'on', 'public_tested' => '1',
    ])->assertRedirect(route('campaigns.production.index'));
    expect(DB::table('email_campaign_release_reviews')->where('id', 1)->value('smtp_per_hour'))->toBe(25)
        ->and(config('campaign_mail.live_enabled'))->toBeFalse();
    Mail::assertNothingSent();
});

it('refuses changing approval while live sending is enabled', function (): void {
    $manager = tap(User::factory()->create(['email_verified_at' => now()]), function (User $u): void {
        $u->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    });
    config()->set('campaign_mail.live_enabled', true);
    $this->actingAs($manager)->post(route('campaigns.production.save'), [
        'smtp_per_hour' => 25, 'smtp_per_day' => 100,
        'smtp_evidence' => 'Limite autorizado por administrador.',
    ])->assertSessionHasErrors('limits');
    expect(DB::table('email_campaign_release_reviews')->count())->toBe(0);
});

it('does not mistake an entered quota for external technical verification', function (): void {
    $snapshot = app(CampaignProductionReadiness::class)->snapshot();
    expect($snapshot['technical_checks'])->toBeFalse()
        ->and($snapshot['smtp_confirmed'])->toBeFalse();
});

it('adds one-click unsubscribe headers only to commercial messages', function (): void {
    $commercial = new ProspectorCampaignMessage('Assunto', 'Corpo', 'https://example.org/email/descadastro/1.'.str_repeat('a', 64));
    $test = new ProspectorCampaignMessage('Assunto', 'Corpo', null, null, true);
    config()->set('campaign_mail.from_address', 'teste@exportcontrol.com.br');
    config()->set('campaign_mail.reply_to', 'teste@exportcontrol.com.br');
    $email = new Email;
    foreach ($commercial->envelope()->using as $callback) {
        $callback($email);
    }
    expect($email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString())->toBe('List-Unsubscribe=One-Click');
    $pilot = new Email;
    foreach ($test->envelope()->using as $callback) {
        $callback($pilot);
    }
    expect($pilot->getHeaders()->has('List-Unsubscribe-Post'))->toBeFalse();
    Mail::assertNothingSent();
});

it('accepts one-click POST but never unsubscribes on GET', function (): void {
    $campaign = EmailCampaign::query()->create([
        'name' => 'Teste one-click', 'subject' => 'Teste', 'body' => 'Mensagem fictícia',
        'status' => 'canceled', 'from_address' => 'teste@exportcontrol.com.br',
        'from_name' => 'ExportControl', 'reply_to' => 'teste@exportcontrol.com.br',
    ]);
    $recipient = $campaign->recipients()->create([
        'email' => 'optout@example.org', 'normalized_email' => 'optout@example.org',
        'status' => 'pending',
    ]);
    $token = app(UnsubscribeToken::class)->forRecipient($recipient);
    $url = route('campaigns.unsubscribe', ['token' => $token]);
    $this->get($url)->assertOk();
    expect(EmailCampaignSuppression::query()->count())->toBe(0);
    $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('Descadastro confirmado.');
    expect(EmailCampaignSuppression::query()->where('normalized_email', 'optout@example.org')->exists())->toBeTrue();
    Mail::assertNothingSent();
});
