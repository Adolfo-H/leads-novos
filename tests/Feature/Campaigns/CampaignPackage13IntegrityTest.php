<?php

use App\Jobs\SendEmailCampaignRecipient;
use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\CustomerRegistryEntry;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignSuppression;
use App\Models\User;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignManager;
use App\Services\Campaigns\CampaignSegmentation;
use App\Services\Campaigns\CampaignSequenceGuard;
use App\Services\Campaigns\CampaignSequencePlanner;
use App\Services\Campaigns\UnsubscribeToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function package13Manager(): User
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

function package13Campaign(string $status = 'draft'): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Pacote 13',
        'subject' => 'Assunto inicial',
        'body' => 'Texto de campanha usado somente nos testes automatizados.',
        'status' => $status,
        'from_address' => 'teste@example.invalid',
        'from_name' => 'Testes',
        'reply_to' => 'teste@example.invalid',
    ]);
}

beforeEach(function (): void {
    config()->set('app.url', 'https://prospector.exportcontrol.com.br');
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.from_address', 'teste@example.invalid');
    config()->set('campaign_mail.from_name', 'Testes');
    config()->set('campaign_mail.reply_to', 'teste@example.invalid');
    config()->set('campaign_mail.per_hour', 25);
    config()->set('campaign_mail.per_day', 500);
    config()->set('campaign_mail.per_tick', 5);
    Mail::fake();
    Http::fake();
    Queue::fake();
});

it('preserves follow-up recipient identity and sequence links when editing the draft', function (): void {
    $manager = package13Manager();
    $contact = CommercialContact::query()->create([
        'name' => 'Contato da sequência',
        'email' => 'sequence@example.invalid',
        'created_by' => $manager->id,
    ]);
    $source = package13Campaign('completed');
    $original = $source->recipients()->create([
        'source_key' => 'manual:'.$contact->id,
        'email' => $contact->email,
        'normalized_email' => $contact->email,
        'status' => 'sent',
        'attempted_at' => now('UTC')->subDays(10),
        'sent_at' => now('UTC')->subDays(10),
    ]);
    $sequenceId = app(CampaignSequencePlanner::class)->create(
        $manager,
        $source,
        'Follow-up planejado',
        1,
        'Assunto follow-up',
        'Texto do follow-up planejado.',
    );
    $draftId = app(CampaignSequencePlanner::class)->prepare($manager, $sequenceId);
    $draft = EmailCampaign::query()->findOrFail($draftId);
    $recipient = $draft->recipients()->firstOrFail();
    $link = DB::table('email_campaign_sequence_links')
        ->where('followup_recipient_id', $recipient->id)->first();

    app(CampaignManager::class)->saveDraft($manager, [
        'name' => $draft->name,
        'subject' => 'Novo assunto follow-up',
        'body' => 'Texto atualizado sem substituir o destinatário.',
    ], ['manual:'.$contact->id], $draft);

    expect($draft->fresh()->subject)->toBe('Novo assunto follow-up')
        ->and($recipient->fresh()->id)->toBe($recipient->id)
        ->and($recipient->fresh()->status)->toBe('pending')
        ->and(DB::table('email_campaign_sequence_links')
            ->where('id', $link->id)
            ->where('original_recipient_id', $original->id)
            ->where('followup_recipient_id', $recipient->id)
            ->exists())->toBeTrue();

    $original->update(['replied_at' => now('UTC')]);
    expect(app(CampaignSequenceGuard::class)->suppressIfAnswered($recipient->fresh()))->toBeTrue()
        ->and($recipient->fresh()->status)->toBe('suppressed');
    Mail::assertNothingSent();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps reply history and cancels removed pending recipients instead of deleting them', function (): void {
    $manager = package13Manager();
    $first = CommercialContact::query()->create([
        'name' => 'Resposta preservada', 'email' => 'reply@example.invalid', 'created_by' => $manager->id,
    ]);
    $removed = CommercialContact::query()->create([
        'name' => 'Removido', 'email' => 'removed@example.invalid', 'created_by' => $manager->id,
    ]);
    $replacement = CommercialContact::query()->create([
        'name' => 'Novo selecionado', 'email' => 'replacement@example.invalid', 'created_by' => $manager->id,
    ]);
    $managerService = app(CampaignManager::class);
    $campaign = $managerService->saveDraft($manager, [
        'name' => 'Rascunho editável',
        'subject' => 'Assunto inicial',
        'body' => 'Texto inicial suficientemente longo para o teste.',
    ], ['manual:'.$first->id, 'manual:'.$removed->id]);
    $firstRecipient = $campaign->recipients()->where('normalized_email', $first->email)->firstOrFail();
    $removedRecipient = $campaign->recipients()->where('normalized_email', $removed->email)->firstOrFail();
    $attemptedAt = now('UTC')->subDays(2);
    $firstRecipient->update([
        'status' => 'replied',
        'attempted_at' => $attemptedAt,
        'sent_at' => $attemptedAt,
        'replied_at' => now('UTC')->subDay(),
        'message_id' => 'reply-history@example.invalid',
    ]);
    EmailCampaignReply::query()->create([
        'email_campaign_id' => $campaign->id,
        'email_campaign_recipient_id' => $firstRecipient->id,
        'mailbox' => 'teste@example.invalid',
        'uid_validity' => '123',
        'imap_uid' => 9,
        'from_email' => $first->email,
        'subject' => 'Resposta registrada',
        'received_at' => now('UTC')->subDay(),
    ]);
    EmailCampaignSuppression::query()->create([
        'normalized_email' => $first->email,
        'reason' => 'unsubscribe',
    ]);

    $managerService->saveDraft($manager, [
        'name' => 'Rascunho editado',
        'subject' => 'Assunto atualizado',
        'body' => 'Texto atualizado suficientemente longo para o teste.',
    ], ['manual:'.$first->id, 'manual:'.$replacement->id], $campaign);

    expect($campaign->recipients()->whereKey($firstRecipient->id)->exists())->toBeTrue()
        ->and($firstRecipient->fresh()->status)->toBe('replied')
        ->and($firstRecipient->fresh()->message_id)->toBe('reply-history@example.invalid')
        ->and($firstRecipient->fresh()->attempted_at->toDateTimeString())->toBe($attemptedAt->toDateTimeString())
        ->and(EmailCampaignReply::query()->where('email_campaign_recipient_id', $firstRecipient->id)->count())->toBe(1)
        ->and($removedRecipient->fresh()->status)->toBe('canceled')
        ->and($campaign->recipients()->whereKey($removedRecipient->id)->exists())->toBeTrue()
        ->and($campaign->recipients()->where('normalized_email', $replacement->email)->where('status', 'pending')->exists())->toBeTrue();

    Mail::assertNothingSent();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('rechecks draft status under lock before editing a stale campaign model', function (): void {
    $manager = package13Manager();
    $contact = CommercialContact::query()->create([
        'name' => 'Contato', 'email' => 'stale@example.invalid', 'created_by' => $manager->id,
    ]);
    $campaign = app(CampaignManager::class)->saveDraft($manager, [
        'name' => 'Rascunho',
        'subject' => 'Assunto',
        'body' => 'Texto de campanha suficientemente longo.',
    ], ['manual:'.$contact->id]);
    EmailCampaign::query()->whereKey($campaign->id)->update(['status' => 'running']);

    expect(fn () => app(CampaignManager::class)->saveDraft($manager, [
        'name' => 'Alteração não permitida',
        'subject' => 'Assunto alterado',
        'body' => 'Texto de campanha suficientemente longo.',
    ], ['manual:'.$contact->id], $campaign))->toThrow(ValidationException::class);
    expect($campaign->fresh()->name)->toBe('Rascunho')
        ->and($campaign->fresh()->status)->toBe('running');
});

it('keeps removed sent history but excludes that recipient from future sequence drafts', function (): void {
    $manager = package13Manager();
    $originalContact = CommercialContact::query()->create([
        'name' => 'Histórico enviado',
        'email' => 'historical@example.invalid',
        'created_by' => $manager->id,
    ]);
    $replacement = CommercialContact::query()->create([
        'name' => 'Contato substituto',
        'email' => 'replacement-sequence@example.invalid',
        'created_by' => $manager->id,
    ]);
    $source = package13Campaign();
    $original = $source->recipients()->create([
        'source_key' => 'manual:'.$originalContact->id,
        'email' => $originalContact->email,
        'normalized_email' => $originalContact->email,
        'status' => 'sent',
        'attempted_at' => now('UTC')->subDays(10),
        'sent_at' => now('UTC')->subDays(10),
    ]);
    $sequenceId = app(CampaignSequencePlanner::class)->create(
        $manager,
        $source,
        'Sequência do histórico',
        1,
        'Assunto da sequência',
        'Texto da sequência.',
    );

    app(CampaignManager::class)->saveDraft($manager, [
        'name' => $source->name,
        'subject' => $source->subject,
        'body' => $source->body,
    ], ['manual:'.$replacement->id], $source);

    expect($original->fresh()->status)->toBe('sent')
        ->and($original->fresh()->excluded_from_sequences_at)->not->toBeNull()
        ->and(app(CampaignSequencePlanner::class)->prepare($manager, $sequenceId))->toBeNull()
        ->and($source->recipients()->whereKey($original->id)->exists())->toBeTrue();
});

it('enforces hourly and daily quotas when queued recipients transition to sending', function (): void {
    config()->set('campaign_mail.live_enabled', true);
    config()->set('campaign_mail.per_hour', 25);
    config()->set('campaign_mail.per_day', 500);
    $campaign = package13Campaign('running');
    $recipients = [];
    foreach (range(1, 50) as $index) {
        $email = 'quota'.$index.'@example.invalid';
        $recipients[] = $campaign->recipients()->create([
            'email' => $email,
            'normalized_email' => $email,
            'status' => 'reserved',
            'reserved_at' => $index <= 25 ? now('UTC')->subHours(2) : now('UTC'),
            'reservation_token' => (string) Str::uuid(),
        ]);
    }

    foreach ($recipients as $recipient) {
        (new SendEmailCampaignRecipient(
            (int) $recipient->id,
            (string) $recipient->reservation_token,
        ))->handle(
            app(CampaignDispatcher::class),
            app(CampaignContent::class),
            app(UnsubscribeToken::class),
        );
    }

    expect($campaign->recipients()->where('status', 'sent')->count())->toBe(25)
        ->and($campaign->recipients()->where('status', 'pending')->count())->toBe(25)
        ->and($campaign->recipients()->whereNotNull('attempted_at')->count())->toBe(25)
        ->and($campaign->recipients()->where('status', 'pending')->whereNotNull('reservation_token')->count())->toBe(0);
    Mail::assertSentCount(25);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('excludes manually linked customers and scans past previously sent contacts before applying the result limit', function (): void {
    $manager = package13Manager();
    $company = Company::factory()->create(['cnpj_root' => '12345678']);
    $company->crmCheck()->create([
        'provider' => 'hubspot',
        'status' => 'client',
        'checked_at' => now('UTC'),
    ]);
    CommercialContact::query()->create([
        'company_id' => $company->id,
        'name' => 'Contato de cliente',
        'email' => 'customer@example.invalid',
        'created_by' => $manager->id,
    ]);
    $registeredCompany = Company::factory()->create(['cnpj_root' => '87654321']);
    CustomerRegistryEntry::query()->create([
        'cnpj_root' => $registeredCompany->cnpj_root,
        'corporate_name' => $registeredCompany->corporate_name,
        'source' => 'test-fixture',
        'enabled' => true,
    ]);
    CommercialContact::query()->create([
        'company_id' => $registeredCompany->id,
        'name' => 'Contato de cliente pelo CNPJ raiz',
        'email' => 'customer-root@example.invalid',
        'created_by' => $manager->id,
    ]);

    $contacts = [];
    for ($index = 1; $index <= 3; $index++) {
        $contacts[] = CommercialContact::query()->create([
            'name' => 'Já contatado '.$index,
            'email' => 'contacted'.$index.'@example.invalid',
            'created_by' => $manager->id,
        ]);
    }
    $eligible = CommercialContact::query()->create([
        'name' => 'Contato elegível',
        'email' => 'eligible@example.invalid',
        'created_by' => $manager->id,
    ]);
    $history = package13Campaign('completed');
    foreach ($contacts as $contact) {
        $history->recipients()->create([
            'email' => $contact->email,
            'normalized_email' => $contact->email,
            'status' => 'sent',
            'attempted_at' => now('UTC')->subDay(),
        ]);
    }

    $result = app(CampaignSegmentation::class)->preview(['origin' => 'manual'], 1);

    expect($result)->toHaveCount(1)
        ->and($result[0]['source_key'])->toBe('manual:'.$eligible->id)
        ->and($result[0]['email'])->toBe($eligible->email);
    Mail::assertNothingSent();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('reports campaign queue and compliance totals without exposing recipient data or dispatching', function (): void {
    $campaign = package13Campaign('completed');
    $recipient = $campaign->recipients()->create([
        'email' => 'private-audit@example.invalid',
        'normalized_email' => 'private-audit@example.invalid',
        'status' => 'uncertain',
        'attempted_at' => now('UTC')->subHour(),
    ]);
    EmailCampaignReply::query()->create([
        'email_campaign_id' => $campaign->id,
        'email_campaign_recipient_id' => $recipient->id,
        'mailbox' => 'teste@example.invalid',
        'uid_validity' => '123',
        'imap_uid' => 42,
        'from_email' => 'private-audit@example.invalid',
        'subject' => 'Resposta',
        'received_at' => now('UTC'),
    ]);
    EmailCampaignSuppression::query()->create([
        'normalized_email' => 'private-audit@example.invalid',
        'reason' => 'unsubscribe',
    ]);
    DB::table('email_campaign_bounces')->insert([
        'email_campaign_id' => $campaign->id,
        'email_campaign_recipient_id' => $recipient->id,
        'mailbox' => 'teste@example.invalid',
        'uid_validity' => '123',
        'imap_uid' => 43,
        'final_recipient' => 'private-audit@example.invalid',
        'status_code' => '5.1.1',
        'action' => 'failed',
        'classification' => 'hard',
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    expect(Artisan::call('campaigns:simulate', ['--recipients' => '10']))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('tentativas=1')
        ->toContain('respostas=1')
        ->toContain('bounces=1')
        ->toContain('opt-outs globais=1')
        ->toContain('NENHUM ENVIO REAL')
        ->not->toContain('private-audit@example.invalid');

    Mail::assertNothingSent();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
