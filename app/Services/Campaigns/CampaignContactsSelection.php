<?php

namespace App\Services\Campaigns;

use Illuminate\Validation\ValidationException;

/**
 * Transferência exata de Contatos para rascunho de Campanhas.
 * Não grava em HubSpot, não altera contatos, não dispara SMTP.
 */
final class CampaignContactsSelection
{
    /**
     * @param  array<mixed>  $keys
     * @param  array<mixed>  $expectedEmails
     * @return array{keys:list<string>,emails:array<string,string>}
     */
    public function validateExact(array $keys, array $expectedEmails): array
    {
        $limit = max(1, min(1000, (int) config('campaign_mail.max_recipients', 100)));
        if ($keys === [] || count($keys) > $limit) {
            throw ValidationException::withMessages([
                'recipients' => 'A seleção está vazia, duplicada ou acima do limite. Selecione os contatos novamente.',
            ]);
        }

        $seenKeys = [];
        foreach ($keys as $key) {
            if (! is_string($key) || preg_match('/^(?:(?:hubspot|manual):[0-9]+|receita:[0-9a-fA-F-]{36})$/D', $key) !== 1
                || isset($seenKeys[$key])) {
                throw ValidationException::withMessages(['recipients' => 'A seleção contém um contato inválido ou duplicado.']);
            }
            $seenKeys[$key] = true;
        }

        $resolved = app(CampaignAudience::class)->resolve(array_values($keys));
        // resolve() não deve suprimir destinatários silenciosamente quando a escolha
        // veio de Contatos: um ID obsoleto, duplicidade ou e-mail alterado bloqueia tudo.
        if (count($resolved) !== count($keys)) {
            throw ValidationException::withMessages([
                'recipients' => 'A seleção mudou ou contém e-mails duplicados. Nenhum destinatário foi transferido. Atualize Contatos e selecione novamente.',
            ]);
        }

        $resolvedByKey = [];
        foreach ($resolved as $entry) {
            $resolvedByKey[$entry['source_key']] = $entry;
        }
        $emails = [];
        foreach ($keys as $key) {
            $entry = $resolvedByKey[$key] ?? null;
            $originalEmail = $expectedEmails[$key] ?? null;
            if ($entry === null || ! is_string($originalEmail)
                || filter_var(CampaignAudience::normalizedEmail($originalEmail), FILTER_VALIDATE_EMAIL) === false
                || CampaignAudience::normalizedEmail($originalEmail) !== $entry['normalized_email']) {
                throw ValidationException::withMessages([
                    'recipients' => 'O e-mail de um contato está diferente do selecionado. Nenhuma substituição foi feita. Atualize Contatos e selecione novamente.',
                ]);
            }
            if ($entry['suppressed']) {
                throw ValidationException::withMessages([
                    'recipients' => 'Um dos endereços selecionados está bloqueado ou descadastrado. Nenhum destinatário foi transferido.',
                ]);
            }
            $emails[$key] = $entry['normalized_email'];
        }

        return ['keys' => array_values($keys), 'emails' => $emails];
    }

    /**
     * @param  array<mixed>  $keys
     * @param  array<mixed>  $expectedEmails
     */
    public function remember(array $keys, array $expectedEmails): void
    {
        $selection = $this->validateExact($keys, $expectedEmails);
        session()->forget(['campaign_mail_selected', 'campaign_mail_expected_emails']);
        session()->put('campaign_mail_selected', $selection['keys']);
        session()->put('campaign_mail_expected_emails', $selection['emails']);
    }
}
