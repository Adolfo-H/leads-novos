<?php

namespace App\Services\Campaigns;

use Illuminate\Validation\ValidationException;

/**
 * Transfere uma seleção explícita de Contatos para a criação de campanhas.
 * Nunca substitui um e-mail marcado por outro que tenha passado a existir no CRM.
 */
final class CampaignContactTransfer
{
    /**
     * @param  array<mixed>  $selected
     * @param  array<array-key, mixed>  $snapshots
     * @return array<string, string>
     */
    public function capture(array $selected, array $snapshots): array
    {
        $limit = max(1, min(1000, (int) config('campaign_mail.max_recipients', 100)));

        if ($selected === [] || count($selected) > $limit) {
            throw ValidationException::withMessages([
                'recipients' => 'Selecione de 1 a '.$limit.' contatos com e-mail para criar uma campanha.',
            ]);
        }

        $expected = [];
        foreach ($selected as $rawKey) {
            if (! is_string($rawKey) || preg_match('/^(?:(?:hubspot|manual):[0-9]+|receita:[0-9a-fA-F-]{36})$/D', $rawKey) !== 1
                || isset($expected[$rawKey])) {
                throw ValidationException::withMessages([
                    'recipients' => 'A seleção contém contato repetido ou identificação inválida. Atualize a lista.',
                ]);
            }

            $provided = $snapshots[$rawKey] ?? null;
            if (! is_string($provided)) {
                throw ValidationException::withMessages([
                    'recipients' => 'Um e-mail da seleção não estava visível na página. Atualize os contatos.',
                ]);
            }
            $email = CampaignAudience::normalizedEmail($provided);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages([
                    'recipients' => 'Foi selecionado um contato sem e-mail válido. Corrija a seleção.',
                ]);
            }
            $expected[$rawKey] = $email;
        }

        $this->guard(array_keys($expected), $expected);

        return $expected;
    }

    /**
     * Confere apenas os contatos originais que continuam selecionados.
     *
     * @param  array<mixed>  $selected
     * @param  array<array-key, mixed>  $expected
     */
    public function guard(array $selected, array $expected): void
    {
        if ($expected === []) {
            return;
        }

        $keys = [];
        foreach ($selected as $rawKey) {
            if (is_string($rawKey) && isset($expected[$rawKey])) {
                $keys[] = $rawKey;
            }
        }
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            return;
        }

        $resolved = app(CampaignAudience::class)->resolve($keys);
        $actual = [];
        foreach ($resolved as $person) {
            $actual[$person['source_key']] = $person;
        }
        if (count($actual) !== count($keys)) {
            throw ValidationException::withMessages([
                'recipients' => 'O contato selecionado não está mais disponível ou compartilha e-mail com outro. Revise a lista; nada foi enviado.',
            ]);
        }

        foreach ($keys as $key) {
            $wanted = $expected[$key] ?? null;
            $person = $actual[$key] ?? null;
            if (! is_string($wanted) || ! is_array($person)
                || ! hash_equals($wanted, (string) $person['normalized_email'])
                || $person['suppressed']) {
                throw ValidationException::withMessages([
                    'recipients' => 'O e-mail de um contato mudou ou foi bloqueado desde a seleção. Nenhum destinatário foi substituído.',
                ]);
            }
        }
    }
}
