<?php

use App\Models\EmailCampaignSuppression;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function ecGlobalV273User(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function ecGlobalV273Preview(User $user, string $wizard): array
{
    return json_decode(Crypt::decryptString((string) Cache::get('campaign-import-v27:'.$user->id.':'.$wizard)), true, 512, JSON_THROW_ON_ERROR);
}

function ecGlobalV273Action(User $user, string $wizard, string $action, int $page = 1): array
{
    return ['wizard_id' => $wizard, 'preview_token' => ecGlobalV273Preview($user, $wizard)['token'], 'action' => $action, 'page' => $page];
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.max_recipients', 1000);
});

it('marks all 1011 valid distinct addresses for review across 41 pages without approving or adding contacts', function (): void {
    $user = ecGlobalV273User();
    $wizard = '27300000-0000-4000-8000-000000000001';
    $key = 'campaigns_wizard_v93_'.$wizard;
    $emails = array_map(static fn (int $i): string => sprintf('p%04d@example.test', $i), range(0, 1010));
    $this->actingAs($user)->withSession([$key => ['expected' => []]])->post(route('campaigns.import-v27.upload'), ['wizard_id' => $wizard, 'source_label' => 'Lista fictícia', 'paste' => implode("\n", $emails)])->assertRedirect();
    $this->post(route('campaigns.import-v27.add'), ecGlobalV273Action($user, $wizard, 'mark_all'))->assertSessionHasNoErrors();
    $preview = ecGlobalV273Preview($user, $wizard);
    expect($preview['marks'])->toHaveCount(1011)->and($preview['reviewed'])->toBe([]);
    $this->get(route('campaigns.import-v27.show', ['wizard_id' => $wizard, 'pagina' => 41]))->assertOk()->assertSee('1.011 de 1.011 marcados no arquivo inteiro')->assertSee('Página 41 de 41');
    $this->post(route('campaigns.import-v27.add'), ecGlobalV273Action($user, $wizard, 'previous_page', 41) + ['selected' => range(1000, 1010)])->assertRedirect(route('campaigns.import-v27.show', ['wizard_id' => $wizard, 'pagina' => 40]));
    expect(ecGlobalV273Preview($user, $wizard)['marks'])->toHaveCount(1011);
    $this->post(route('campaigns.import-v27.add'), ecGlobalV273Action($user, $wizard, 'add_reviewed') + ['permission_confirmed' => '1', 'selected' => [0], 'evidence' => [0 => 'Não revisado']])->assertSessionHasErrors('selected');
    expect(DB::table('email_campaign_import_contacts')->count())->toBe(0)->and(session($key)['expected'])->toBe([]);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('preserves checked rows and unconfirmed reference text on previous and next page and never approves them by navigating', function (): void {
    $user = ecGlobalV273User();
    $wizard = '27300000-0000-4000-8000-000000000002';
    $key = 'campaigns_wizard_v93_'.$wizard;
    $emails = array_map(static fn (int $i): string => sprintf('p%02d@example.test', $i), range(0, 29));
    $this->actingAs($user)->withSession([$key => ['expected' => []]])->post(route('campaigns.import-v27.upload'), ['wizard_id' => $wizard, 'source_label' => 'Lista fictícia', 'paste' => implode("\n", $emails)])->assertRedirect();
    $this->post(route('campaigns.import-v27.add'), ecGlobalV273Action($user, $wizard, 'next_page') + ['selected' => [0, 4], 'evidence' => [0 => 'Referência em edição']])->assertRedirect(route('campaigns.import-v27.show', ['wizard_id' => $wizard, 'pagina' => 2]));
    $this->post(route('campaigns.import-v27.add'), ecGlobalV273Action($user, $wizard, 'previous_page', 2) + ['selected' => [26]])->assertRedirect(route('campaigns.import-v27.show', ['wizard_id' => $wizard, 'pagina' => 1]));
    $preview = ecGlobalV273Preview($user, $wizard);
    expect($preview['marks'])->toBe([0 => true, 4 => true, 26 => true])->and($preview['reviewed'])->toBe([])->and($preview['draft_evidence'][0])->toBe('Referência em edição');
    $this->get(route('campaigns.import-v27.show', ['wizard_id' => $wizard]))->assertOk()->assertSee('3 de 30 marcados no arquivo inteiro')->assertSee('Referência em edição');
    $this->post(route('campaigns.import-v27.add'), ecGlobalV273Action($user, $wizard, 'keep_marks') + ['selected' => [26]])->assertSessionHasErrors('selected');
    $this->post(route('campaigns.import-v27.add'), ecGlobalV273Action($user, $wizard, 'clear_marks'))->assertSessionHasNoErrors();
    expect(ecGlobalV273Preview($user, $wizard)['marks'])->toBe([]);
    expect(DB::table('email_campaign_import_contacts')->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('excludes blocked and already chosen addresses and respects selection, remapping and stale tokens', function (): void {
    $user = ecGlobalV273User();
    $wizard = '27300000-0000-4000-8000-000000000003';
    $key = 'campaigns_wizard_v93_'.$wizard;
    EmailCampaignSuppression::query()->create(['normalized_email' => 'blocked@example.test', 'reason' => 'unsubscribe']);
    $this->actingAs($user)->withSession([$key => ['expected' => ['manual:99' => 'chosen@example.test']]])->post(route('campaigns.import-v27.upload'), ['wizard_id' => $wizard, 'source_label' => 'Lista fictícia', 'paste' => 'blocked@example.test chosen@example.test valid@example.test'])->assertRedirect();
    $action = ecGlobalV273Action($user, $wizard, 'mark_all');
    $this->post(route('campaigns.import-v27.add'), $action)->assertSessionHasNoErrors();
    expect(ecGlobalV273Preview($user, $wizard)['marks'])->toBe([2 => true]);
    $this->post(route('campaigns.import-v27.add'), $action)->assertSessionHasErrors('import_file');
    $this->post(route('campaigns.import-v27.add'), ecGlobalV273Action($user, $wizard, 'review_page') + ['selected' => [2], 'evidence' => [2 => 'Referência fictícia válida'], 'permission_confirmed' => '1'])->assertSessionHasNoErrors();
    $this->post(route('campaigns.import-v27.add'), ecGlobalV273Action($user, $wizard, 'clear_marks'))->assertSessionHasNoErrors();
    $this->post(route('campaigns.import-v27.add'), ecGlobalV273Action($user, $wizard, 'add_reviewed') + ['permission_confirmed' => '1'])->assertSessionHasErrors('selected');
    $this->post(route('campaigns.import-v27.mapping'), ['wizard_id' => $wizard, 'preview_token' => ecGlobalV273Preview($user, $wizard)['token'], 'mapping' => ['email' => 0]])->assertSessionHasNoErrors();
    expect(ecGlobalV273Preview($user, $wizard))->not->toHaveKeys(['marks', 'reviewed', 'draft_evidence']);
    expect(DB::table('email_campaign_import_contacts')->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
