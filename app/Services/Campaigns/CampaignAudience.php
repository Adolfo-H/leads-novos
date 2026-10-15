<?php

namespace App\Services\Campaigns;

use App\Models\CommercialContact;
use App\Models\EmailCampaignSuppression;
use App\Models\HubSpotContact;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Diretório para campanhas. Um contato HubSpot = uma pessoa, mesmo com múltiplas associações.
 * O campo company_name do HubSpot é informativo, NUNCA um vínculo fiscal.
 * A Receita não gera destinatários pessoais fictícios.
 */
final class CampaignAudience
{
    public static function normalizedEmail(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /** @return LengthAwarePaginator<int, stdClass> */
    public function paginate(string $search, string $origin, int $page = 1): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, stdClass> $results */
        $results = $this->query($search, $origin)
            ->orderBy('person_name')->orderBy('source_key')
            ->paginate(12, ['*'], 'audiencePage', max(1, $page));

        return $results;
    }

    /** @return list<string> */
    public function allMatchingKeys(string $search, string $origin, int $cap): array
    {
        $keys = $this->query($search, $origin)
            ->orderBy('source_key')->limit($cap + 1)->pluck('source_key')->all();

        if (count($keys) > $cap) {
            throw new InvalidArgumentException('A seleção filtrada ultrapassa o limite desta campanha. Refine a pesquisa.');
        }

        return array_values(array_map(static fn (mixed $key): string => (string) $key, $keys));
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{source_key:string,email:string,normalized_email:string,contact_name:string,company_name:string,job_title:string,suppressed:bool}>
     */
    public function resolve(array $keys): array
    {
        $cap = max(1, min(1000, (int) config('campaign_mail.max_recipients', 100)));
        $keys = array_values(array_unique($keys));
        if (count($keys) > $cap) {
            throw new InvalidArgumentException('A campanha ultrapassa o limite de destinatários configurado.');
        }

        $results = [];
        $usedEmails = [];
        foreach ($keys as $key) {
            if (preg_match('/^hubspot:([0-9]+)$/D', $key, $match) === 1) {
                $person = HubSpotContact::query()->where('hubspot_id', $match[1])->first();
                if ($person === null) {
                    continue;
                }
                $name = trim((string) $person->first_name.' '.(string) $person->last_name);
                $email = (string) $person->email;
                $company = (string) $person->company_name;
                $job = (string) $person->job_title;
            } elseif (preg_match('/^manual:([0-9]+)$/D', $key, $match) === 1) {
                $person = CommercialContact::query()->with('company')->find((int) $match[1]);
                if ($person === null) {
                    continue;
                }
                $name = (string) $person->name;
                $email = (string) $person->email;
                $company = (string) ($person->company->corporate_name ?? '');
                $job = (string) $person->job_title;
            } else {
                continue;
            }

            $normal = self::normalizedEmail($email);
            if (filter_var($normal, FILTER_VALIDATE_EMAIL) === false || isset($usedEmails[$normal])) {
                continue;
            }
            $usedEmails[$normal] = true;
            $results[] = [
                'source_key' => $key,
                'email' => $normal,
                'normalized_email' => $normal,
                'contact_name' => mb_substr($name, 0, 180),
                'company_name' => mb_substr($company, 0, 255),
                'job_title' => mb_substr($job, 0, 180),
                'suppressed' => EmailCampaignSuppression::query()->where('normalized_email', $normal)->exists(),
            ];
        }

        return $results;
    }

    private function query(string $search, string $origin): Builder
    {
        $hubspot = DB::table('hubspot_contacts as h')
            ->selectRaw("('hubspot:' || CAST(h.hubspot_id AS VARCHAR)) AS source_key")
            ->selectRaw("TRIM(COALESCE(h.first_name, '') || ' ' || COALESCE(h.last_name, '')) AS person_name")
            ->selectRaw('h.email AS email, h.job_title AS job_title, h.company_name AS company_name')
            ->selectRaw("'hubspot' AS origin")
            ->whereNotNull('h.email')->where('h.email', '<>', '');

        $manual = DB::table('commercial_contacts as m')
            ->leftJoin('companies as c', 'c.id', '=', 'm.company_id')
            ->selectRaw("('manual:' || CAST(m.id AS VARCHAR)) AS source_key")
            ->selectRaw('m.name AS person_name, m.email AS email, m.job_title AS job_title, c.corporate_name AS company_name')
            ->selectRaw("'manual' AS origin")
            ->whereNotNull('m.email')->where('m.email', '<>', '');

        $query = DB::query()->fromSub($hubspot->unionAll($manual), 'audience')
            ->select('audience.*');

        if (in_array($origin, ['hubspot', 'manual'], true)) {
            $query->where('origin', $origin);
        }

        $search = mb_strtolower(trim(mb_substr($search, 0, 120)));
        if ($search !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(static function (Builder $q) use ($like): void {
                $q->whereRaw("LOWER(COALESCE(person_name, '')) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(email, '')) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(company_name, '')) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(job_title, '')) LIKE ? ESCAPE '!'", [$like]);
            });
        }

        return $query;
    }
}
