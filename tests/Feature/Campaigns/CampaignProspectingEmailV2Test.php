<?php

use App\Models\EmailCampaignRecipient;
use App\Models\User;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignProspectingTemplates;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('tem modelos curtos e distintos para trading e exportacao direta', function (): void {
    $trading = CampaignProspectingTemplates::template('initial');
    $direct = CampaignProspectingTemplates::template('direct');

    expect(CampaignProspectingTemplates::keys())->toContain('initial', 'direct', 'followup', 'meeting')
        ->and($trading[0])->toContain('tradings')
        ->and($trading[1])->toContain('ICMS')
        ->and($trading[1])->toContain('indicar com quem conversar')
        ->and($direct[1])->toContain('DU-Es')
        ->and($direct[1])->toContain('Saldos pendentes')
        ->and($direct[1])->not->toContain('vendas para tradings')
        ->and(mb_strlen($trading[1]))->toBeLessThan(850)
        ->and(mb_strlen($direct[1]))->toBeLessThan(850);
});

it('personaliza saudacao sem duplicar ola quando nao ha nome', function (): void {
    $renderer = app(CampaignContent::class);
    $content = CampaignProspectingTemplates::template('initial')[1];

    $ana = new EmailCampaignRecipient(['contact_name' => 'Ana Maria', 'company_name' => 'Cerealista Azul']);
    $semNome = new EmailCampaignRecipient(['contact_name' => '', 'company_name' => '']);

    expect($renderer->render($content, $ana))->toContain('Olá, Ana!')
        ->toContain('A Cerealista Azul realiza vendas')
        ->and($renderer->render($content, $semNome))->toContain('Olá!')
        ->not->toContain('Olá, Olá!');
});

it('oferece os modelos no assistente sem iniciar envios', function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $wizardId = '0fa78ccc-0000-4000-8000-000000000001';

    $this->actingAs($manager)->get(route('campaigns.wizard-v93', [
        'wizard_id' => $wizardId, 'reiniciar' => 1, 'modelo' => 'direct',
    ]))->assertOk();

    $state = session('campaigns_wizard_v93_'.$wizardId);
    expect($state['model'])->toBe('direct')
        ->and($state['subject'])->toContain('averbações');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('troca o modelo sem perder os contatos selecionados e sem enviar emails', function (): void {
    Mail::fake();
    Queue::fake();
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $wizardId = '0fa78ccc-0000-4000-8000-000000000002';
    $key = 'campaigns_wizard_v93_'.$wizardId;
    $state = [
        'expected' => ['hubspot:123' => 'compras@example.invalid'],
        'model' => 'initial',
        'name' => 'Campanha de teste',
        'subject' => 'Assunto antigo',
        'body' => 'Mensagem anterior',
        'hours' => '8',
    ];

    $this->actingAs($manager)->withSession([$key => $state])->post(
        route('campaigns.wizard-v93.choose-template'),
        ['wizard_id' => $wizardId, 'model' => 'direct'],
    )->assertRedirect(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'mensagem']));

    $new = session($key);
    expect($new['model'])->toBe('direct')
        ->and($new['expected'])->toBe($state['expected'])
        ->and($new['name'])->toBe($state['name'])
        ->and($new['hours'])->toBe('8')
        ->and($new['subject'])->toContain('averbações')
        ->and($new['body'])->toContain('comprovação fiscal');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('mantem email html seguro, curto, com imagens e descadastro', function (): void {
    $message = new class
    {
        public function embed(string $path): string
        {
            return 'cid:'.basename($path);
        }
    };

    $html = view('mail.prospector-campaign-html', [
        'message' => $message,
        'messageBody' => "Olá!\n\n<script>alert('x')</script>\n\nPodemos conversar?",
        'unsubscribeUrl' => 'https://empresa.example.invalid/cancelar',
        'isTest' => false,
    ])->render();

    expect($html)->toContain('cid:exportcontrol-campanhas-banner.jpg')
        ->not->toContain('cid:pngexportcontrol.png')
        ->toContain('Equipe Comercial')
        ->not->toContain('exportcontrol-assinatura-adolfo.jpg')
        ->toContain('solicite o descadastro')
        ->toContain('Atenciosamente,')
        ->toContain('Conheça nossas soluções')
        ->toContain('href="https://exportcontrol.com.br"')
        ->not->toContain('>exportcontrol.com.br<')
        ->not->toContain('>https://exportcontrol.com.br<')
        ->not->toContain('mailto:')
        ->not->toContain('Conversar pelo WhatsApp')
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>alert')
        ->not->toContain('01 / CONFERÊNCIA')
        ->not->toContain('03 / RASTREABILIDADE');
});
