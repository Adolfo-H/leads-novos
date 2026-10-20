<?php

namespace App\Services\Campaigns;

use App\Services\Contacts\ReceitaContactChannels;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Seleciona contatos REAIS HubSpot/manuais sem inventar vínculos com Receita/CNPJ. */
final class CampaignSegmentation
{
    // EC_RECEITA_UNIFIED_V10: canais de e-mail corporativo da Receita exigem revisão comercial.

    /** @param array<string, string|bool> $filters
     * @return list<array{source_key:string,email:string,name:string,company:string,job:string,origin:string,lifecycle:string,needs_review:bool}>
     */
    public function preview(array $filters, int $max = 100): array
    {
        $max = max(1, min(500, $max));
        $hubspot = DB::table('hubspot_contacts as h')
            ->selectRaw("('hubspot:' || CAST(h.hubspot_id AS VARCHAR)) AS source_key")
            ->selectRaw("TRIM(COALESCE(h.first_name, '') || ' ' || COALESCE(h.last_name, '')) AS person_name")
            ->selectRaw('h.email AS email, h.company_name AS company_name, h.job_title AS job_title, h.lifecycle_stage AS lifecycle')
            ->selectRaw("'hubspot' AS origin")
            ->whereNotNull('h.hubspot_id')->whereNotNull('h.email')->where('h.email', '<>', '');
        $manual = DB::table('commercial_contacts as m')
            ->leftJoin('companies as c', 'c.id', '=', 'm.company_id')
            ->selectRaw("('manual:' || CAST(m.id AS VARCHAR)) AS source_key")
            ->selectRaw('m.name AS person_name, m.email AS email, c.corporate_name AS company_name, m.job_title AS job_title')
            ->selectRaw("'' AS lifecycle, 'manual' AS origin")
            ->whereNotNull('m.email')->where('m.email', '<>', '');
        $receita = ReceitaContactChannels::withEmail()
            ->selectRaw("('receita:' || CAST(e.uuid AS VARCHAR)) AS source_key")
            ->selectRaw("'Equipe' AS person_name")
            ->selectRaw("e.email AS email, rc.corporate_name AS company_name, 'Canal cadastral da Receita' AS job_title")
            ->selectRaw("CASE WHEN EXISTS (SELECT 1 FROM company_crm_checks cc WHERE cc.company_id = e.company_id AND cc.status = 'client') THEN 'customer' ELSE '' END AS lifecycle")
            ->selectRaw("'receita' AS origin");
        $query = DB::query()->fromSub($hubspot->unionAll($manual)->unionAll($receita), 'contacts');
        $origin = (string) ($filters['origin'] ?? 'all');
        if (in_array($origin, ['hubspot', 'manual', 'receita'], true)) {
            $query->where('origin', $origin);
        }
        foreach (['company' => 'company_name', 'job' => 'job_title', 'search' => 'person_name'] as $key => $column) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(mb_substr($value, 0, 100))).'%';
                $query->whereRaw('LOWER(COALESCE('.$column.", '')) LIKE ? ESCAPE '!'", [$pattern]);
            }
        }
        $excludeCustomers = ($filters['exclude_customers'] ?? true) !== false && ($filters['exclude_customers'] ?? '1') !== '0';
        if ($excludeCustomers) {
            $query->where(static function (Builder $query): void {
                $query->where('origin', 'manual')
                    ->orWhereRaw("LOWER(COALESCE(lifecycle, '')) <> 'customer'");
            });
        }
        $query->whereNotExists(static function (Builder $sub): void {
            $sub->selectRaw('1')->from('email_campaign_suppressions as s')
                ->whereRaw('s.normalized_email = LOWER(TRIM(contacts.email))');
        });
        $items = $query->orderBy('origin')->orderBy('source_key')->limit($max + 1)->get();
        $used = [];
        $rows = [];
        foreach ($items as $item) {
            $email = CampaignAudience::normalizedEmail((string) $item->email);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($used[$email])) {
                continue;
            }
            // Evita repetição de disparos: quem já foi aceito pelo SMTP deve ser tratado por follow-up específico.
            if (DB::table('email_campaign_recipients')->where('normalized_email', $email)->whereIn('status', ['sent', 'sending', 'reserved', 'uncertain'])->exists()) {
                continue;
            }
            $used[$email] = true;
            $rows[] = [
                'source_key' => (string) $item->source_key,
                'email' => $email,
                'name' => (string) $item->person_name,
                'company' => (string) ($item->company_name ?? ''),
                'job' => (string) ($item->job_title ?? ''),
                'origin' => (string) $item->origin,
                'lifecycle' => (string) ($item->lifecycle ?? ''),
                'needs_review' => in_array((string) $item->origin, ['manual', 'receita'], true),
            ];
            if (count($rows) >= $max) {
                break;
            }
        }

        return $rows;
    }
}
