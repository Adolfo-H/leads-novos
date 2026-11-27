<?php

use App\Mail\ProspectorCampaignMessage;
use App\Services\Campaigns\CampaignInstitutionalSignature;
use Illuminate\Support\Facades\Mail;

it('keeps the institutional envelope and only the website CTA in both MIME alternatives', function (string $key): void {
    Mail::fake();
    config()->set('campaign_mail.from_address', 'legacy@example.invalid');
    config()->set('campaign_mail.from_name', 'Nome pessoal antigo');
    config()->set('campaign_mail.reply_to', 'legacy-reply@example.invalid');
    $address = $key.'@exportcontrol.com.br';
    $other = ($key === 'publicidade' ? 'propaganda' : 'publicidade').'@exportcontrol.com.br';
    $unsubscribe = 'https://example.invalid/descadastro/abc';
    $mailable = new ProspectorCampaignMessage('Teste de assinatura', "Mensagem\n\n<script>alert('x')</script>", $unsubscribe, 'assinatura@example.invalid', false, $address);
    $envelope = $mailable->envelope();
    expect($envelope->from->address)->toBe($address)
        ->and($envelope->from->name)->toBe(CampaignInstitutionalSignature::DISPLAY_NAME)
        ->and($envelope->replyTo[0]->address)->toBe($address);

    $message = new class
    {
        public function embed(string $path): string
        {
            return 'cid:'.basename($path);
        }
    };
    $data = $mailable->content()->with;
    $html = view('mail.prospector-campaign-html', array_merge($data, ['message' => $message]))->render();
    $plain = view('mail.prospector-campaign-plain', $data)->render();
    foreach ([$html, $plain] as $body) {
        expect($body)->toContain('Equipe Comercial')
            ->toContain('Soluções para Controle das Exportações')
            ->not->toContain($address)
            ->toContain('Conheça nossas soluções')
            ->toContain(CampaignInstitutionalSignature::SITE)
            ->toContain($unsubscribe)
            ->not->toContain($other)
            ->not->toContain('Adolfo Heerdt')
            ->not->toContain('adolfo@exportcontrol.com.br')
            ->not->toContain('99102-6597')
            ->not->toContain('wa.me/')
            ->not->toContain('exportcontrol-assinatura-adolfo.jpg');
    }
    expect($html)->toContain('cid:exportcontrol-campanhas-banner.jpg')
        ->not->toContain('cid:pngexportcontrol.png')
        ->toContain('href="'.CampaignInstitutionalSignature::SITE.'"')
        ->not->toContain('>exportcontrol.com.br<')
        ->not->toContain('>'.CampaignInstitutionalSignature::SITE.'<')
        ->not->toContain('mailto:')
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>');
    Mail::assertNothingSent();
})->with(['publicidade', 'propaganda']);

it('preserves the legacy SMTP identity and never inserts an invented contact in its institutional signature', function (): void {
    Mail::fake();
    config()->set('campaign_mail.from_address', 'legacy@example.invalid');
    config()->set('campaign_mail.from_name', 'Identidade legada');
    config()->set('campaign_mail.reply_to', 'legacy-reply@example.invalid');
    $mailable = new ProspectorCampaignMessage('Teste legado', 'Corpo', null, null, true);
    $envelope = $mailable->envelope();
    expect($envelope->from->address)->toBe('legacy@example.invalid')
        ->and($envelope->from->name)->toBe('Identidade legada')
        ->and($envelope->replyTo[0]->address)->toBe('legacy-reply@example.invalid');
    $plain = view('mail.prospector-campaign-plain', $mailable->content()->with)->render();
    expect($plain)->toContain('Equipe Comercial')
        ->toContain('Atenciosamente,')
        ->toContain('Conheça nossas soluções: '.CampaignInstitutionalSignature::SITE)
        ->toContain('MENSAGEM DE TESTE')
        ->not->toContain('legacy@example.invalid')
        ->not->toContain('publicidade@exportcontrol.com.br')
        ->not->toContain('propaganda@exportcontrol.com.br');
    Mail::assertNothingSent();
});

it('shows the institutional signature preview without selecting a transport', function (): void {
    Mail::fake();
    $html = view('campaigns.institutional-signature-preview', ['signatureCampaign' => null])->render();
    expect($html)->toContain('data-ec-institutional-signature-preview')
        ->toContain('Equipe Comercial')
        ->toContain('Prévia da assinatura institucional atual')
        ->not->toContain('pngexportcontrol.png')
        ->toContain('Conheça nossas soluções')
        ->toContain('href="'.CampaignInstitutionalSignature::SITE.'"')
        ->not->toContain('>exportcontrol.com.br<')
        ->not->toContain('>'.CampaignInstitutionalSignature::SITE.'<')
        ->not->toContain('mailto:')
        ->not->toContain('Adolfo Heerdt');
    Mail::assertNothingSent();
});
