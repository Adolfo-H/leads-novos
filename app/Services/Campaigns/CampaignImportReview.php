<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Str;

/** Normalization and mapping only. No consent is inferred from an address or domain. */
final class CampaignImportReview
{
    public const FIELDS = [
        'email' => 'E-mail (obrigatório)',
        'name' => 'Nome completo',
        'company' => 'Empresa',
        'job' => 'Cargo',
        'type' => 'Tipo do e-mail',
    ];

    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     * @return array<string, int>
     */
    public function autoMapping(array $columns, array $rows): array
    {
        $aliases = [
            'email' => ['email', 'e_mail', 'email_address', 'endereco_de_email', 'endereco_email', 'email_corporativo', 'email_pessoal'],
            'name' => ['full_name', 'nome_completo', 'name', 'nome', 'contact_name', 'person_name'],
            'company' => ['current_company', 'empresa', 'company', 'company_name', 'razao_social', 'original_current_company'],
            'job' => ['current_company_position', 'cargo', 'job_title', 'position', 'funcao', 'original_current_company_position'],
            'type' => ['email_type', 'tipo_email', 'tipo_de_email'],
        ];
        $normalized = array_map(static fn (string $v): string => Str::slug($v, '_'), $columns);
        $mapping = array_fill_keys(array_keys(self::FIELDS), -1);
        foreach ($aliases as $field => $choices) {
            foreach ($choices as $choice) {
                $found = array_search($choice, $normalized, true);
                if ($found !== false) {
                    $mapping[$field] = $found;
                    break;
                }
            }
        }
        if ($mapping['email'] === -1) {
            $best = 0;
            foreach (array_keys($columns) as $index) {
                $count = 0;
                foreach (array_slice($rows, 0, 30) as $row) {
                    if (filter_var(trim($row[$index] ?? ''), FILTER_VALIDATE_EMAIL) !== false) {
                        $count++;
                    }
                }
                if ($count > $best) {
                    $best = $count;
                    $mapping['email'] = $index;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int>  $mapping
     * @return array{contacts:list<array{index:int,email:string,name:string,company:string,job:string,type:string}>,invalid:int,duplicates:int,personal:int,business:int,unknown:int}
     */
    public function review(array $rows, array $mapping): array
    {
        $contacts = [];
        $seen = [];
        $invalid = 0;
        $duplicates = 0;
        $personal = 0;
        $business = 0;
        $unknown = 0;
        foreach ($rows as $index => $row) {
            $email = mb_strtolower(trim($row[$mapping['email'] ?? -1] ?? ''));
            if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $invalid++;

                continue;
            }
            if (isset($seen[$email])) {
                $duplicates++;

                continue;
            }
            $seen[$email] = true;
            $declared = Str::slug($row[$mapping['type'] ?? -1] ?? '', '_');
            $domain = substr(strrchr($email, '@') ?: '', 1);
            $type = 'unknown';
            if (in_array($declared, ['personal', 'pessoal', 'private'], true) || in_array($domain, ['gmail.com', 'hotmail.com', 'outlook.com', 'live.com', 'yahoo.com', 'yahoo.com.br', 'icloud.com', 'bol.com.br', 'uol.com.br'], true)) {
                $type = 'personal';
                $personal++;
            } elseif (in_array($declared, ['business', 'corporate', 'corporativo', 'profissional', 'comercial'], true)) {
                $type = 'business';
                $business++;
            } else {
                $unknown++;
            }
            $contacts[] = [
                'index' => $index,
                'email' => $email,
                'name' => mb_substr(trim($row[$mapping['name'] ?? -1] ?? ''), 0, 180),
                'company' => mb_substr(trim($row[$mapping['company'] ?? -1] ?? ''), 0, 255),
                'job' => mb_substr(trim($row[$mapping['job'] ?? -1] ?? ''), 0, 180),
                'type' => $type,
            ];
        }

        return ['contacts' => $contacts, 'invalid' => $invalid, 'duplicates' => $duplicates, 'personal' => $personal, 'business' => $business, 'unknown' => $unknown];
    }
}
