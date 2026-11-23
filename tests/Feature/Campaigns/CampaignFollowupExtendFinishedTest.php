<?php

use App\Livewire\CampaignFollowupEditor;
use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupDates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** @return array{0:User,1:EmailCampaign,2:EmailCampaign,3:int} */
function extensionV2Fixture(string $status = 'completed', bool $explicit = true): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $root = EmailCampaign::query()->create([
        'name' => 'Campanha 1', 'subject' => 'Prospeccao ExportControl',
        'body' => 'Apresentamos a plataforma de controle de exportacoes da ExportControl.',
        'from_address' => 'test@example.invalid', 'from_name' => 'ExportControl',
        'reply_to' => 'test@example.invalid', 'status' => 'completed',
        'completed_at' => now('UTC')->subDays(8), 'created_by' => $user->id,
    ]);
    $root->recipients()->create([
        'email' => 'cliente@example.invalid', 'normalized_email' => 'cliente@example.invalid',
        'status' => 'sent', 'sent_at' => now('UTC')->subDays(8),
    ]);
    $second = EmailCampaign::query()->create([
        'name' => '[Rodada 2] Retomada comercial',
        'subject' => 'RE: Prospeccao ExportControl',
        'body' => 'Acompanhamento comercial sobre exportacoes.',
        'status' => $status,
        'completed_at' => $status === 'completed' ? now('UTC')->subDays(2) : null,
        'from_address' => 'test@example.invalid', 'from_name' => 'ExportControl',
        'reply_to' => 'test@example.invalid', 'created_by' => $user->id,
        'followup_root_campaign_id' => $root->id, 'followup_round' => 2,
    ]);
    $second->recipients()->create([
        'email' => 'cliente@example.invalid', 'normalized_email' => 'cliente@example.invalid',
        'status' => 'sent', 'sent_at' => now('UTC')->subDays(2),
    ]);
    $id = (int) DB::table('email_campaign_sequences')->insertGetId([
        'email_campaign_id' => $root->id, 'last_campaign_id' => $second->id,
        'name' => 'Retomada comercial', 'subject' => 'RE: Prospeccao ExportControl',
        'body' => 'Acompanhamento comercial sobre exportacoes.', 'delay_days' => 5,
        'automatic' => true, 'status' => 'finished',
        'max_rounds' => 1, 'sent_rounds' => 1,
        'send_time_local' => '08:00',
        'send_dates' => $explicit ? json_encode([2 => now('America/Sao_Paulo')->subDays(4)->format('Y-m-d')], JSON_THROW_ON_ERROR) : null,
        'created_by' => $user->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);

    return [$user, $root, $second, $id];
}

it('exibe programar terceira rodada mesmo quando as duas anteriores terminaram', function (): void {
    [$user, $root] = extensionV2Fixture();
    Livewire::actingAs($user)->test(CampaignFollowupEditor::class, ['campaignId' => $root->id])
        ->assertSee('Programar a 3ª rodada')
        ->assertSee('Quantidade após salvar: 3 rodadas no total')
        ->assertSee('Autorizo somente a 3ª rodada');
});

it('programa somente a terceira rodada mantendo o historico e sem SMTP', function (): void {
    [$user, $root, $second, $id] = extensionV2Fixture();
    Mail::fake();
    Queue::fake();
    $date = now('America/Sao_Paulo')->addDays(5)->format('Y-m-d');

    $this->actingAs($user)->post(route('campaigns.followup.extend', ['campaign' => $root->id]), [
        'sequence_id' => $id, 'next_date' => $date, 'confirm' => '1',
    ])->assertRedirect()->assertSessionHas('followup_extension_ok');

    $row = DB::table('email_campaign_sequences')->find($id);
    expect($row->status)->toBe('active')
        ->and((int) $row->max_rounds)->toBe(2)
        ->and((int) $row->sent_rounds)->toBe(1)
        ->and((int) $row->last_campaign_id)->toBe((int) $second->id)
        ->and(app(CampaignFollowupDates::class)->dates($row)[3])->toBe($date)
        ->and(EmailCampaign::query()->count())->toBe(2);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('nao reabre quando a segunda rodada ainda esta enviando', function (): void {
    [$user, $root, , $id] = extensionV2Fixture('running');
    $this->actingAs($user)->post(route('campaigns.followup.extend', ['campaign' => $root->id]), [
        'sequence_id' => $id,
        'next_date' => now('America/Sao_Paulo')->addDays(4)->format('Y-m-d'), 'confirm' => '1',
    ])->assertSessionHasErrors('next_date');
    expect(DB::table('email_campaign_sequences')->where('id', $id)->value('status'))->toBe('finished');
});

it('impede duplicidade e exige uma data futura e confirmacao explicita', function (): void {
    [$user, $root, , $id] = extensionV2Fixture();
    $url = route('campaigns.followup.extend', ['campaign' => $root->id]);
    $this->actingAs($user)->post($url, [
        'sequence_id' => $id,
        'next_date' => now('America/Sao_Paulo')->subDay()->format('Y-m-d'), 'confirm' => '1',
    ])->assertSessionHasErrors('next_date');
    $this->actingAs($user)->post($url, [
        'sequence_id' => $id,
        'next_date' => now('America/Sao_Paulo')->addDays(5)->format('Y-m-d'),
    ])->assertSessionHasErrors('confirm');
    expect(DB::table('email_campaign_sequences')->where('id', $id)->value('max_rounds'))->toBe(1);

    $date = now('America/Sao_Paulo')->addDays(5)->format('Y-m-d');
    $this->actingAs($user)->post($url, [
        'sequence_id' => $id, 'next_date' => $date, 'confirm' => '1',
    ])->assertRedirect();
    $this->actingAs($user)->post($url, [
        'sequence_id' => $id, 'next_date' => $date, 'confirm' => '1',
    ])->assertSessionHasErrors('next_date');
    expect(DB::table('email_campaign_sequences')->where('id', $id)->value('max_rounds'))->toBe(2);
});

it('nao altera planos historicos sem datas explicitas', function (): void {
    [$user, $root, , $id] = extensionV2Fixture('completed', false);
    $this->actingAs($user)->post(route('campaigns.followup.extend', ['campaign' => $root->id]), [
        'sequence_id' => $id,
        'next_date' => now('America/Sao_Paulo')->addDays(5)->format('Y-m-d'), 'confirm' => '1',
    ])->assertSessionHasErrors('next_date');
    expect(DB::table('email_campaign_sequences')->where('id', $id)->value('status'))->toBe('finished');
});

it('impede vendedores de programar novo disparo', function (): void {
    [$user, $root, , $id] = extensionV2Fixture();
    $user->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($user)->post(route('campaigns.followup.extend', ['campaign' => $root->id]), [
        'sequence_id' => $id,
        'next_date' => now('America/Sao_Paulo')->addDays(5)->format('Y-m-d'), 'confirm' => '1',
    ])->assertForbidden();
    expect(DB::table('email_campaign_sequences')->where('id', $id)->value('status'))->toBe('finished');
});
