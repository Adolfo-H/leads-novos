<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignSuppression;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignImportedRecipientGuard;
use App\Services\Campaigns\CampaignImportReview;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function ecImportV27User(string $role = User::ROLE_MANAGER): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => $role])->save();

    return $user;
}

function ecImportV27State(): array
{
    return ['expected' => [], 'name' => 'Importação de teste', 'model' => 'initial', 'subject' => 'Assunto fictício', 'body' => 'Mensagem fictícia sem disparos.', 'hours' => '0'];
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.max_recipients', 100);
});

it('maps the five real columns and flags invalid, duplicated and personal addresses without granting permission', function (): void {
    $review = app(CampaignImportReview::class);
    $columns = ['full_name', 'email', 'current_company', 'current_company_position', 'email_type'];
    $rows = [['Ana Exemplo', 'ANA@example.test', 'Empresa', 'Cargo', 'business'], ['Duplicada', 'ana@example.test', '', '', 'business'], ['Bia', 'bia@gmail.com', '', '', 'personal'], ['Inválido', 'x', '', '', '']];
    $mapping = $review->autoMapping($columns, $rows);
    expect($mapping)->toBe(['email' => 1, 'name' => 0, 'company' => 2, 'job' => 3, 'type' => 4]);
    $result = $review->review($rows, $mapping);
    expect($result['contacts'])->toHaveCount(2)
        ->and($result['duplicates'])->toBe(1)
        ->and($result['invalid'])->toBe(1)
        ->and($result['personal'])->toBe(1)
        ->and($result['business'])->toBe(1);
    expect(DB::table('email_campaign_import_contacts')->count())->toBe(0);
});

it('requires a manager and an existing wizard', function (): void {
    $wizard = '27000000-0000-4000-8000-000000000001';
    $this->actingAs(ecImportV27User(User::ROLE_SELLER))->withSession(['campaigns_wizard_v93_'.$wizard => ecImportV27State()])
        ->get(route('campaigns.import-v27.show', ['wizard_id' => $wizard]))->assertForbidden();
    $this->actingAs(ecImportV27User())->get(route('campaigns.import-v27.show', ['wizard_id' => '27000000-0000-4000-8000-000000000099']))->assertNotFound();
});

it('imports a semicolon CSV only to preview and requires individual documented permission before saving a draft', function (): void {
    $user = ecImportV27User();
    $wizard = '27000000-0000-4000-8000-000000000002';
    $sessionKey = 'campaigns_wizard_v93_'.$wizard;
    $this->actingAs($user)->withSession([$sessionKey => ecImportV27State()]);
    EmailCampaignSuppression::query()->create(['normalized_email' => 'blocked@example.test', 'reason' => 'unsubscribe']);
    $file = UploadedFile::fake()->createWithContent('fixture.csv', "email;full_name;current_company;current_company_position;email_type\nana@example.test;Ana Exemplo;Empresa Azul;Gestora;business\nblocked@example.test;Bia;Empresa;Cargo;business\nANA@example.test;Duplicada;;;business\n");
    $this->post(route('campaigns.import-v27.upload'), ['wizard_id' => $wizard, 'source_label' => 'Formulário de aceite de teste', 'import_file' => $file])->assertRedirect();
    $this->get(route('campaigns.import-v27.show', ['wizard_id' => $wizard]))->assertOk()->assertSee('Ana Exemplo')->assertSee('Revisar autorização')->assertSee('Bloqueado');
    expect(DB::table('email_campaign_import_contacts')->count())->toBe(0);
    $encrypted = Cache::get('campaign-import-v27:'.$user->id.':'.$wizard);
    expect($encrypted)->toBeString()->not->toContain('ana@example.test');
    $preview = json_decode(Crypt::decryptString((string) $encrypted), true, 512, JSON_THROW_ON_ERROR);
    $base = ['wizard_id' => $wizard, 'preview_token' => $preview['token'], 'selected' => [0], 'permission_confirmed' => '1'];
    $this->post(route('campaigns.import-v27.add'), $base + ['evidence' => [0 => '']])->assertSessionHasErrors('evidence');
    $this->post(route('campaigns.import-v27.add'), array_replace($base, ['selected' => [1], 'evidence' => [1 => 'Aceite fictício 2026-001']]))->assertSessionHasErrors('selected');
    expect(DB::table('email_campaign_import_contacts')->count())->toBe(0);
    $this->post(route('campaigns.import-v27.add'), $base + ['evidence' => [0 => 'Registro fictício de aceite 2026-002']])->assertSessionHasNoErrors()->assertRedirect();
    $record = DB::table('email_campaign_import_contacts')->first();
    expect($record->email)->toBe('ana@example.test')->and($record->person_name)->toBe('Ana Exemplo');
    expect(session($sessionKey)['expected'])->toBe(['import:'.$record->id => 'ana@example.test']);
    $this->post(route('campaigns.wizard-v93.save'), ['wizard_id' => $wizard])->assertRedirect();
    $campaign = EmailCampaign::query()->where('name', 'Importação de teste')->firstOrFail();
    $recipient = $campaign->recipients()->firstOrFail();
    expect($campaign->status)->toBe('draft')->and($recipient->contact_name)->toBe('Ana Exemplo')->and($recipient->company_name)->toBe('Empresa Azul')->and($recipient->job_title)->toBe('Gestora');
    app(CampaignImportedRecipientGuard::class)->assertReviewed($campaign);
    $recipient->forceFill(['normalized_email' => 'alterado@example.test'])->save();
    expect(fn () => app(CampaignImportedRecipientGuard::class)->assertReviewed($campaign))->toThrow(ValidationException::class);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps previews isolated by owner and rejects expired or stale preview tokens', function (): void {
    $first = ecImportV27User();
    $second = ecImportV27User();
    $wizard = '27000000-0000-4000-8000-000000000003';
    $key = 'campaigns_wizard_v93_'.$wizard;
    $this->actingAs($first)->withSession([$key => ecImportV27State()])->post(route('campaigns.import-v27.upload'), ['wizard_id' => $wizard, 'source_label' => 'Lista interna fictícia', 'paste' => 'contato@example.test'])->assertRedirect();
    $this->actingAs($second)->withSession([$key => ecImportV27State()])->get(route('campaigns.import-v27.show', ['wizard_id' => $wizard]))->assertOk()->assertDontSee('contato@example.test');
    $this->post(route('campaigns.import-v27.add'), ['wizard_id' => $wizard, 'preview_token' => 'invalid', 'selected' => [0], 'permission_confirmed' => '1', 'evidence' => [0 => 'Registro fictício']])->assertSessionHasErrors('import_file');
    expect(DB::table('email_campaign_import_contacts')->count())->toBe(0);
    Mail::assertNothingSent();
});

it('resolves only reviewed imports belonging to the current manager and never exposes them in general contacts', function (): void {
    $first = ecImportV27User();
    $second = ecImportV27User();
    $id = '27000000-0000-4000-8000-000000000004';
    DB::table('email_campaign_import_contacts')->insert([
        'id' => $id, 'owner_id' => $first->id, 'wizard_id' => '27000000-0000-4000-8000-000000000005',
        'email' => 'reviewed@example.test', 'person_name' => 'Fictício', 'email_type' => 'unknown',
        'source_label' => 'Fonte de teste', 'file_label' => 'fixture.csv', 'permission_reference' => Crypt::encryptString('Aceite de teste'), 'reviewed_at' => now(),
    ]);
    $audience = app(CampaignAudience::class);
    $this->actingAs($second);
    expect($audience->resolve(['import:'.$id]))->toBe([]);
    $this->actingAs($first);
    expect($audience->resolve(['import:'.$id]))->toHaveCount(1);
    expect($audience->paginate('reviewed@example.test', 'all')->total())->toBe(0);
});
