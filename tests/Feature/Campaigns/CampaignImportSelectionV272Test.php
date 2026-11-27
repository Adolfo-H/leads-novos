<?php

use App\Models\EmailCampaignSuppression;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function ecSelectionV272User(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function ecSelectionV272Token(User $user, string $wizard): string
{
    $preview = json_decode(Crypt::decryptString((string) Cache::get('campaign-import-v27:'.$user->id.':'.$wizard)), true, 512, JSON_THROW_ON_ERROR);

    return (string) $preview['token'];
}

function ecSelectionV272Review(User $user, string $wizard, int $page, array $indexes): array
{
    $proof = [];
    foreach ($indexes as $index) {
        $proof[$index] = 'Registro fictício de autorização '.$index;
    }

    return ['wizard_id' => $wizard, 'preview_token' => ecSelectionV272Token($user, $wizard), 'action' => 'review_page', 'page' => $page, 'selected' => $indexes, 'evidence' => $proof, 'permission_confirmed' => '1'];
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.max_recipients', 100);
});

it('marks and saves individual reviews across pages and adds only available reviewed contacts in one action', function (): void {
    $user = ecSelectionV272User();
    $wizard = '27200000-0000-4000-8000-000000000001';
    $key = 'campaigns_wizard_v93_'.$wizard;
    $this->actingAs($user)->withSession([$key => ['expected' => []]]);
    $emails = array_map(static fn (int $i): string => sprintf('p%02d@example.test', $i), range(0, 29));
    $this->post(route('campaigns.import-v27.upload'), ['wizard_id' => $wizard, 'source_label' => 'Lista fictícia de autorizações', 'paste' => implode("\n", $emails)])->assertRedirect();
    $this->get(route('campaigns.import-v27.show', ['wizard_id' => $wizard]))->assertOk()->assertSee('Selecionar todos da página')->assertSee('Adicionar todos os revisados');
    $this->post(route('campaigns.import-v27.add'), ecSelectionV272Review($user, $wizard, 1, range(0, 24)))->assertSessionHasNoErrors()->assertRedirect();
    $this->post(route('campaigns.import-v27.add'), ecSelectionV272Review($user, $wizard, 2, range(25, 28)))->assertSessionHasNoErrors()->assertRedirect();
    expect(DB::table('email_campaign_import_contacts')->count())->toBe(0);
    expect(session($key)['expected'])->toBe([]);
    $this->get(route('campaigns.import-v27.show', ['wizard_id' => $wizard, 'pagina' => 2]))->assertOk()->assertSee('29 revisado(s) disponível(is)')->assertSee('Revisão guardada');
    EmailCampaignSuppression::query()->create(['normalized_email' => $emails[2], 'reason' => 'unsubscribe']);
    $this->post(route('campaigns.import-v27.add'), ['wizard_id' => $wizard, 'preview_token' => ecSelectionV272Token($user, $wizard), 'action' => 'add_reviewed', 'permission_confirmed' => '1', 'selected' => [29], 'evidence' => [29 => 'Referência não revisada']])->assertSessionHasNoErrors()->assertRedirect();
    expect(DB::table('email_campaign_import_contacts')->count())->toBe(28);
    expect(session($key)['expected'])->toHaveCount(28);
    expect(array_values(session($key)['expected']))->not->toContain($emails[2], $emails[29]);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('rejects bulk additions above the existing cap and rejects missing evidence and stale tokens', function (): void {
    $user = ecSelectionV272User();
    $wizard = '27200000-0000-4000-8000-000000000002';
    $this->actingAs($user)->withSession(['campaigns_wizard_v93_'.$wizard => ['expected' => []]]);
    config()->set('campaign_mail.max_recipients', 1);
    $this->post(route('campaigns.import-v27.upload'), ['wizard_id' => $wizard, 'source_label' => 'Lista fictícia de autorizações', 'paste' => 'a@example.test b@example.test'])->assertRedirect();
    $data = ecSelectionV272Review($user, $wizard, 1, [0, 1]);
    $this->post(route('campaigns.import-v27.add'), array_replace($data, ['evidence' => [0 => '']]))->assertSessionHasErrors('evidence');
    $this->post(route('campaigns.import-v27.add'), $data)->assertSessionHasNoErrors();
    $this->post(route('campaigns.import-v27.add'), ['wizard_id' => $wizard, 'preview_token' => $data['preview_token'], 'action' => 'add_reviewed', 'permission_confirmed' => '1'])->assertSessionHasErrors('import_file');
    $this->post(route('campaigns.import-v27.add'), ['wizard_id' => $wizard, 'preview_token' => ecSelectionV272Token($user, $wizard), 'action' => 'add_reviewed', 'permission_confirmed' => '1'])->assertSessionHasErrors('selected');
    expect(DB::table('email_campaign_import_contacts')->count())->toBe(0);
    $this->post(route('campaigns.import-v27.add'), ecSelectionV272Review($user, $wizard, 1, [1]))->assertSessionHasNoErrors();
    $this->post(route('campaigns.import-v27.add'), ['wizard_id' => $wizard, 'preview_token' => ecSelectionV272Token($user, $wizard), 'action' => 'add_reviewed', 'permission_confirmed' => '1'])->assertSessionHasNoErrors();
    expect(DB::table('email_campaign_import_contacts')->count())->toBe(1);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('never accepts an unreviewed client bulk list, and clears saved reviews when remapping', function (): void {
    $user = ecSelectionV272User();
    $wizard = '27200000-0000-4000-8000-000000000003';
    $this->actingAs($user)->withSession(['campaigns_wizard_v93_'.$wizard => ['expected' => []]]);
    $this->post(route('campaigns.import-v27.upload'), ['wizard_id' => $wizard, 'source_label' => 'Lista fictícia de autorizações', 'paste' => 'a@example.test'])->assertRedirect();
    $token = ecSelectionV272Token($user, $wizard);
    $this->post(route('campaigns.import-v27.add'), ['wizard_id' => $wizard, 'preview_token' => $token, 'action' => 'add_reviewed', 'permission_confirmed' => '1', 'selected' => [0], 'evidence' => [0 => 'Referência não revisada']])->assertSessionHasErrors('selected');
    $this->post(route('campaigns.import-v27.add'), ecSelectionV272Review($user, $wizard, 1, [0]))->assertSessionHasNoErrors();
    $this->post(route('campaigns.import-v27.mapping'), ['wizard_id' => $wizard, 'preview_token' => ecSelectionV272Token($user, $wizard), 'mapping' => ['email' => 0]])->assertSessionHasNoErrors();
    $this->post(route('campaigns.import-v27.add'), ['wizard_id' => $wizard, 'preview_token' => ecSelectionV272Token($user, $wizard), 'action' => 'add_reviewed', 'permission_confirmed' => '1'])->assertSessionHasErrors('selected');
    expect(DB::table('email_campaign_import_contacts')->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
