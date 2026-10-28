<?php

namespace App\Services;

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;

/**
 * Dados de pessoas realmente vinculadas aos registros do espelho HubSpot.
 *
 * Usa somente associações explícitas (Company↔Contact e Deal↔Contact).
 * Não faz chamadas à API, não inventa contatos nem propaga vínculo fiscal.
 */
// EC_PROSPECTOR_V51_CONTACT_COLUMNS: nomes ao lado de telefones e e-mails.
final class HubSpotContactExportV50Service
{
    /**
     * @return array{names:string,details:string,emails:string,phones:string,namedPhones:string,namedEmails:string}
     */
    public function forFiscalCompany(Company $company): array
    {
        $company->loadMissing([
            'hubSpotCompanies.contacts',
            'hubSpotCompanies.commercialDeals.contacts',
        ]);

        /** @var list<HubSpotContact> $contacts */
        $contacts = [];
        foreach ($company->hubSpotCompanies as $hubSpotCompany) {
            foreach ($hubSpotCompany->contacts as $contact) {
                $contacts[] = $contact;
            }
            foreach ($hubSpotCompany->commercialDeals as $deal) {
                foreach ($deal->contacts as $contact) {
                    $contacts[] = $contact;
                }
            }
        }

        return $this->summarize($contacts);
    }

    /**
     * @return array{names:string,details:string,emails:string,phones:string,namedPhones:string,namedEmails:string}
     */
    public function forHubSpotCompany(HubSpotCompany $company): array
    {
        $company->loadMissing(['contacts', 'deals.contacts']);

        /** @var list<HubSpotContact> $contacts */
        $contacts = [];
        foreach ($company->contacts as $contact) {
            $contacts[] = $contact;
        }
        foreach ($company->deals as $deal) {
            foreach ($deal->contacts as $contact) {
                $contacts[] = $contact;
            }
        }

        return $this->summarize($contacts);
    }

    /**
     * @return array{names:string,details:string,emails:string,phones:string,namedPhones:string,namedEmails:string}
     */
    public function forHubSpotDeal(HubSpotDeal $deal, ?HubSpotCompany $primaryCompany): array
    {
        $deal->loadMissing('contacts');

        /** @var list<HubSpotContact> $contacts */
        $contacts = [];
        foreach ($deal->contacts as $contact) {
            $contacts[] = $contact;
        }

        // Adicionar contatos só da Company escolhida para representar o Deal,
        // nunca de outra Company secundária associada ao mesmo negócio.
        if ($primaryCompany !== null) {
            $primaryCompany->loadMissing('contacts');
            foreach ($primaryCompany->contacts as $contact) {
                $contacts[] = $contact;
            }
        }

        return $this->summarize($contacts);
    }

    /**
     * Mantém o telefone/e-mail fiscal da empresa separado dos contatos do HubSpot.
     * Se não há contato identificado, preserva exatamente o campo antigo.
     */
    public function withAssociatedContacts(string $existing, string $withNames): string
    {
        $existing = trim($existing);
        $withNames = trim($withNames);
        if ($existing === '') {
            return $withNames;
        }
        if ($withNames === '') {
            return $existing;
        }

        return $this->excelLength('Empresa: '.$existing.' | '.$withNames);
    }

    /** Junta contatos aos dados fiscais existentes sem descartar valores. */
    public function mergeValues(string $existing, string $fromHubSpot): string
    {
        $values = [];
        foreach ([$existing, $fromHubSpot] as $group) {
            foreach (explode(' | ', $group) as $value) {
                $value = trim($value);
                if ($value !== '') {
                    $key = mb_strtolower($value);
                    $values[$key] = $values[$key] ?? $value;
                }
            }
        }

        return implode(' | ', array_values($values));
    }

    /**
     * @param  list<HubSpotContact>  $contacts
     * @return array{names:string,details:string,emails:string,phones:string,namedPhones:string,namedEmails:string}
     */
    private function summarize(array $contacts): array
    {
        /** @var array<string, HubSpotContact> $unique */
        $unique = [];
        foreach ($contacts as $contact) {
            $hubspotId = trim((string) $contact->hubspot_id);
            $key = $hubspotId !== '' ? 'hubspot:'.$hubspotId : 'local:'.(string) $contact->getKey();
            $unique[$key] = $contact;
        }

        $names = [];
        $details = [];
        $emails = [];
        $phones = [];
        $namedPhones = [];
        $namedEmails = [];

        foreach ($unique as $contact) {
            $firstName = $this->plainText($contact->first_name);
            $lastName = $this->plainText($contact->last_name);
            $name = trim($firstName.' '.$lastName);
            $email = $this->plainText($contact->email);
            $phone = $this->plainText($contact->phone);
            $mobile = $this->plainText($contact->mobile_phone);
            $jobTitle = $this->plainText($contact->job_title);

            if ($name === '') {
                $name = $email !== '' ? $email : 'Contato sem nome';
            }
            $names[] = $name;
            $phonesForPerson = [];

            $parts = [$name];
            if ($jobTitle !== '') {
                $parts[] = 'Cargo: '.$jobTitle;
            }
            if ($email !== '') {
                $parts[] = 'E-mail: '.$email;
                $emails[mb_strtolower($email)] = $email;
                $namedEmails[] = $name.': '.$email;
            }
            if ($phone !== '') {
                $parts[] = 'Telefone: '.$phone;
                $phones[$this->phoneKey($phone)] = $phone;
                $phonesForPerson[$this->phoneKey($phone)] = $phone;
            }
            if ($mobile !== '') {
                $parts[] = 'Celular: '.$mobile;
                $phones[$this->phoneKey($mobile)] = $mobile;
                $phonesForPerson[$this->phoneKey($mobile)] = $mobile;
            }
            $details[] = implode(' · ', $parts);
            if ($phonesForPerson !== []) {
                $namedPhones[] = $name.': '.implode(' / ', array_values($phonesForPerson));
            }
        }

        return [
            'names' => $this->excelLength(implode(' | ', $names)),
            'details' => $this->excelLength(implode("\n", $details)),
            'emails' => implode(' | ', array_values($emails)),
            'phones' => implode(' | ', array_values($phones)),
            'namedPhones' => $this->excelLength(implode(' | ', $namedPhones)),
            'namedEmails' => $this->excelLength(implode(' | ', $namedEmails)),
        ];
    }

    private function plainText(mixed $value): string
    {
        // Não deixar tabulações e quebras de linha de campos livres quebrar
        // a associação entre nome, telefone e e-mail no Excel.
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function phoneKey(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return $digits !== '' ? $digits : mb_strtolower($phone);
    }

    private function excelLength(string $text): string
    {
        // Limite oficial de texto por célula do XLSX: 32.767 caracteres.
        if (mb_strlen($text) <= 32767) {
            return $text;
        }

        return mb_substr($text, 0, 32700).' … [lista de contatos abreviada]';
    }
}
