<?php

// EC_FOLLOWUP_CANCEL_NEXT_V3: seguranca e regressao do cancelamento.

use App\Livewire\CampaignFollowupEditor;
use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupDates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** @return array{0:User,1:EmailCampaign,2:EmailCampaign,3:int} */
function cancelV3Scenario(string $status = 'paused'): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $root = EmailCampaign::query()->create([
        'name' => 'Original ExportControl',
        'subject' => 'Contato comercial',
        'body' => 'Apresentação das ferramentas ExportControl para o cliente.',
        'from_address' => 'sender@example.invalid', 'from_name' => 'ExportControl',
        'reply_to' => 'sender@example.invalid',
        'status' => 'completed', 'completed_at' => now('UTC')->subDays(9),
        'created_by' => $user->id,
    ]);
    $root->recipients()->create([
        'email' => 'cliente@example.invalid', 'normalized_email' => 'cliente@example.invalid',
        'status' => 'sent', 'sent_at' => now('UTC')->subDays(9),
    ]);
    $second = EmailCampaign::query()->create([
        'name' => '[Rodada 2] Retomada', 'subject' => 'RE: Contato',
        'body' => 'Retomada da apresentação da ExportControl.',
        'from_address' => 'sender@example.invalid', 'from_name' => 'ExportControl',
        'reply_to' => 'sender@example.invalid',
        'status' => 'completed', 'completed_at' => now('UTC')->subDays(3),
        'created_by' => $user->id,
        'followup_root_campaign_id' => $root->id, 'followup_round' => 2,
    ]);
    $second->recipients()->create([
        'email' => 'cliente@example.invalid', 'normalized_email' => 'cliente@example.invalid',
        'status' => 'sent', 'sent_at' => now('UTC')->subDays(3),
    ]);
    $dates = [
        2 => now('America/Sao_Paulo')->subDays(5)->format('Y-m-d'),
        3 => now('America/Sao_Paulo')->addDays(5)->format('Y-m-d'),
    ];
    $id = (int) DB::table('email_campaign_sequences')->insertGetId([
        'email_campaign_id' => $root->id,
        'last_campaign_id' => $second->id,
        'name' => 'Retomada comercial',
        'subject' => 'RE: Contato',
        'body' => 'Uma mensagem comercial de acompanhamento.',
        'delay_days' => 5,
        'status' => $status,
        'automatic' => true,
        'max_rounds' => 2,
        'sent_rounds' => 1,
        'send_time_local' => '08:00',
        'send_dates' => json_encode($dates, JSON_THROW_ON_ERROR),
        'created_by' => $user->id,
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);

    return [$user, $root, $second, $id];
}

it('exibe cancelamento da terceira somente para uma sequencia com data e rodada anterior iniciada', function (): void {
    [$user, $root] = cancelV3Scenario();
    Livewire::actingAs($user)
        ->test(CampaignFollowupEditor::class, ['campaignId' => $root->id])
        ->assertSee('Cancelar a 3ª rodada')
        ->assertSee('cancelar-proximas-rodadas');
});

it('cancela a terceira ainda nao iniciada, sem criar ou alterar campanhas e sem SMTP', function (): void {
    [$user, $root, $second, $id] = cancelV3Scenario();
    Mail::fake();
    Queue::fake();

    $this->actingAs($user)->post(route('campaigns.followup.cancel-next', ['campaign' => $root->id]), [
        'sequence_id' => $id,
        'next_round' => 3,
        'confirm' => '1',
    ])->assertRedirect()->assertSessionHas('followup_cancel_ok');

    $sequence = DB::table('email_campaign_sequences')->find($id);
    expect((string) $sequence->status)->toBe('finished')
        ->and((int) $sequence->max_rounds)->toBe(1)
        ->and((int) $sequence->sent_rounds)->toBe(1)
        ->and(app(CampaignFollowupDates::class)->dates($sequence))->toHaveKey(2)
        ->and(app(CampaignFollowupDates::class)->dates($sequence))->not->toHaveKey(3)
        ->and(EmailCampaign::query()->count())->toBe(2)
        ->and($second->fresh()->status)->toBe('completed');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('nao autoriza uma segunda tentativa de cancelar uma rodada finalizada', function (): void {
    [$user, $root, , $id] = cancelV3Scenario('active');
    $url = route('campaigns.followup.cancel-next', ['campaign' => $root->id]);
    $payload = ['sequence_id' => $id, 'next_round' => 3, 'confirm' => '1'];
    $this->actingAs($user)->post($url, $payload)->assertRedirect();
    $this->actingAs($user)->post($url, $payload)->assertSessionHasErrors('cancel_next');
    expect(DB::table('email_campaign_sequences')->where('id', $id)->value('status'))->toBe('finished');
});

it('nao cancela rodada que ja possui campanha criada', function (): void {
    [$user, $root, , $id] = cancelV3Scenario('active');
    EmailCampaign::query()->create([
        'name' => '[Rodada 3] Ja preparada', 'subject' => 'RE: Contato',
        'body' => 'Texto de follow-up ja criado anteriormente.',
        'from_address' => 'sender@example.invalid', 'from_name' => 'ExportControl',
        'reply_to' => 'sender@example.invalid',
        'status' => 'draft', 'created_by' => $user->id,
        'followup_root_campaign_id' => $root->id, 'followup_round' => 3,
    ]);
    $this->actingAs($user)->post(route('campaigns.followup.cancel-next', ['campaign' => $root->id]), [
        'sequence_id' => $id, 'next_round' => 3, 'confirm' => '1',
    ])->assertSessionHasErrors('cancel_next');
    expect(DB::table('email_campaign_sequences')->where('id', $id)->value('status'))->toBe('active');
});

it('exige autorizacao do gestor e confirmacao consciente', function (): void {
    [$user, $root, , $id] = cancelV3Scenario();
    $url = route('campaigns.followup.cancel-next', ['campaign' => $root->id]);
    $this->actingAs($user)->post($url, ['sequence_id' => $id, 'next_round' => 3])
        ->assertSessionHasErrors('confirm');
    $user->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($user)->post($url, [
        'sequence_id' => $id, 'next_round' => 3, 'confirm' => '1',
    ])->assertForbidden();
    expect(DB::table('email_campaign_sequences')->where('id', $id)->value('status'))->toBe('paused');
});

it('exibe links reais e datas sem +dias nos indicadores e no cronograma', function (): void {
    $viewHome = file_get_contents(resource_path('views/campaigns/followup-timeline-home.blade.php'));
    $viewCampaigns = file_get_contents(resource_path('views/campaigns/home.blade.php'));
    $viewDetail = file_get_contents(resource_path('views/campaigns/followup-timeline-detail.blade.php'));
    expect($viewHome)->toContain('EC_FOLLOWUP_HOME_HTTP_V3')
        ->toContain('#ecv5-cronograma')
        ->not->toContain('wire:click="openCampaign(')
        ->not->toContain("['days'] }} dias")
        ->and($viewCampaigns)->toContain('EC_FOLLOWUP_LIST_DATE_V3')
        ->not->toContain('+{{ (int) $ec80Plan->delay_days }} dias')
        ->and($viewDetail)->toContain('EC_FOLLOWUP_DETAIL_DATE_V3');
});
