<?php

use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupDates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function calendarFixtureV1(): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $root = EmailCampaign::query()->create([
        'name' => 'Campanha inicial',
        'subject' => 'Contato ExportControl',
        'body' => 'Apresentação comercial para conhecer as soluções ExportControl.',
        'from_address' => 'teste@example.invalid',
        'from_name' => 'ExportControl',
        'reply_to' => 'teste@example.invalid',
        'status' => 'completed',
        'completed_at' => now('UTC')->subDays(2),
        'created_by' => $user->id,
    ]);
    $root->recipients()->create([
        'email' => 'cliente@example.invalid',
        'normalized_email' => 'cliente@example.invalid',
        'status' => 'sent',
        'sent_at' => now('UTC')->subDays(2),
    ]);

    return [$user, $root];
}

it('interpreta duas rodadas como uma inicial e somente um retorno com data e hora fixa', function (): void {
    [$user, $root] = calendarFixtureV1();
    Mail::fake();
    Queue::fake();
    $date = now('America/Sao_Paulo')->addDays(5)->format('Y-m-d');

    $this->actingAs($user)->post(route('campaigns.followup.calendar', ['campaign' => $root->id]), [
        'name' => 'Retomada comercial',
        'total_rounds' => 2,
        'round_dates' => [2 => $date],
        'subject' => 'RE: Contato ExportControl',
        'body' => 'Olá, podemos conversar sobre os controles de exportação?',
    ])->assertRedirect();

    $plan = DB::table('email_campaign_sequences')->where('email_campaign_id', $root->id)->first();
    expect($plan)->not->toBeNull()
        ->and((int) $plan->max_rounds)->toBe(1)
        ->and((int) $plan->sent_rounds)->toBe(0)
        ->and($plan->send_time_local)->toBe('08:00')
        ->and(app(CampaignFollowupDates::class)->dates($plan)[2])->toBe($date)
        ->and(EmailCampaign::query()->count())->toBe(1);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('encerrar na segunda rodada nao cria terceira e nao interfere na campanha ja iniciada', function (): void {
    [$user, $root] = calendarFixtureV1();
    $second = EmailCampaign::query()->create([
        'name' => 'Segunda rodada',
        'subject' => 'Retomada comercial',
        'body' => 'Mensagem de acompanhamento enviada pela segunda rodada.',
        'status' => 'running',
        'from_address' => 'teste@example.invalid',
        'from_name' => 'ExportControl',
        'reply_to' => 'teste@example.invalid',
        'created_by' => $user->id,
        'followup_root_campaign_id' => $root->id,
        'followup_round' => 2,
    ]);
    DB::table('email_campaign_sequences')->insert([
        'email_campaign_id' => $root->id,
        'last_campaign_id' => $second->id,
        'name' => 'Retomada comercial',
        'subject' => 'Retomada comercial',
        'body' => 'Mensagem de acompanhamento enviada pela segunda rodada.',
        'delay_days' => 5,
        'max_rounds' => 2,
        'sent_rounds' => 1,
        'status' => 'paused',
        'automatic' => true,
        'created_by' => $user->id,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    $this->actingAs($user)->post(route('campaigns.followup.calendar', ['campaign' => $root->id]), [
        'name' => 'Retomada comercial',
        'total_rounds' => 2,
        'round_dates' => [],
        'subject' => 'Retomada comercial',
        'body' => 'Mensagem de acompanhamento enviada pela segunda rodada.',
    ])->assertRedirect();

    $plan = DB::table('email_campaign_sequences')->where('email_campaign_id', $root->id)->first();
    expect($plan->status)->toBe('finished')
        ->and((int) $plan->sent_rounds)->toBe(1)
        ->and((int) $plan->max_rounds)->toBe(1)
        ->and((int) $plan->last_campaign_id)->toBe((int) $second->id)
        ->and($second->fresh()->status)->toBe('running')
        ->and(EmailCampaign::query()->count())->toBe(2);
});

it('nunca inicia uma data de 08h depois da janela de 15 minutos', function (): void {
    [, $root] = calendarFixtureV1();
    $date = now('America/Sao_Paulo')->addDays(6)->format('Y-m-d');
    $calendar = app(CampaignFollowupDates::class);
    $plan = (object) ['send_dates' => json_encode(['2' => $date], JSON_THROW_ON_ERROR)];
    $due = $calendar->dateForRound($plan, 2);
    expect($due)->not->toBeNull()
        ->and($calendar->state($plan, 2, $root, $due->subSecond()))->toBe('scheduled')
        ->and($calendar->state($plan, 2, $root, $due->addMinutes(5)))->toBe('ready')
        ->and($calendar->state($plan, 2, $root, $due->addMinutes(15)))->toBe('expired')
        ->and($calendar->state($plan, 2, $root, $due->addHours(10)))->toBe('expired');
});

it('impede configurar um retorno com data vencida ou sem data', function (): void {
    [$user, $root] = calendarFixtureV1();
    $base = [
        'name' => 'Retomada comercial',
        'total_rounds' => 2,
        'subject' => 'RE: Contato ExportControl',
        'body' => 'Olá, podemos conversar sobre nossas soluções?',
    ];
    $yesterday = now('America/Sao_Paulo')->subDay()->format('Y-m-d');
    $this->actingAs($user)->post(route('campaigns.followup.calendar', ['campaign' => $root->id]),
        $base + ['round_dates' => [2 => $yesterday]])->assertSessionHasErrors();
    $this->actingAs($user)->post(route('campaigns.followup.calendar', ['campaign' => $root->id]),
        $base)->assertSessionHasErrors();
    expect(DB::table('email_campaign_sequences')->count())->toBe(0);
});

it('rejeita configuracao por vendedor e nao cria planos', function (): void {
    [$manager, $root] = calendarFixtureV1();
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->post(route('campaigns.followup.calendar', ['campaign' => $root->id]), [])
        ->assertForbidden();
    expect(DB::table('email_campaign_sequences')->count())->toBe(0);
});
