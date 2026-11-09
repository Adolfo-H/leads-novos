<?php

namespace App\Services\Contacts;

use App\Models\User;
use App\Support\TextNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * V89: revisão HUMANA dos números descobertos pela V87/V88.
 * - A empresa é um cadastro concreto, escolhido explicitamente pelo gestor;
 * - a aprovação NÃO altera HubSpot/Receita e NÃO confirma WhatsApp;
 * - a associação e sua fonte persistem mesmo após a reconstrução do índice.
 */
final class WhatsappCompanyPhoneApprovalV89
{
    private const APPROVALS = 'whatsapp_company_web_approved_v89';

    private const FINDINGS = 'whatsapp_company_web_findings_v87';

    /**
     * @return list<array{key:string,name:string,source:string}>
     */
    public function targets(string $companyName): array
    {
        $normal = TextNormalizer::companyName($companyName) ?? '';
        if (mb_strlen($normal) < 3) {
            return [];
        }
        $needle = mb_strtolower(mb_substr($companyName, 0, 110));
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($needle)).'%';
        $result = [];
        $hubspot = DB::table('hubspot_companies')
            ->whereRaw("LOWER(COALESCE(name, '')) LIKE ? ESCAPE '!'", [$like])
            ->orderBy('name')->limit(35)->get(['id', 'name', 'domain']);
        foreach ($hubspot as $row) {
            $name = trim((string) data_get($row, 'name', ''));
            if ($name !== '') {
                $result[] = [
                    'key' => 'hubspot:'.(int) data_get($row, 'id'),
                    'name' => mb_substr($name, 0, 180),
                    'source' => 'Empresa HubSpot',
                ];
            }
        }
        $local = DB::table('companies')
            ->whereRaw("LOWER(COALESCE(corporate_name, '')) LIKE ? ESCAPE '!'", [$like])
            ->orderBy('corporate_name')->limit(35)->get(['id', 'corporate_name', 'cnpj_root']);
        foreach ($local as $row) {
            $name = trim((string) data_get($row, 'corporate_name', ''));
            if ($name !== '') {
                $root = trim((string) data_get($row, 'cnpj_root', ''));
                $result[] = [
                    'key' => 'local:'.(int) data_get($row, 'id'),
                    'name' => mb_substr($name, 0, 180),
                    'source' => 'Empresa Prospector/Receita'.($root !== '' ? ' · CNPJ base '.$root : ''),
                ];
            }
        }

        return $result;
    }

    /**
     * Retorna uma leitura de lote: nenhum SELECT por telefone e nenhum status alterado.
     *
     * @param  list<string>  $phoneNumbers
     * @return array<string,array{phone_id:int|null,exists:bool,status:string,contacts_count:int,approved_here:bool}>
     */
    public function inventory(array $phoneNumbers, string $targetKey = ''): array
    {
        $phones = array_values(array_unique(array_filter($phoneNumbers,
            static fn (string $p): bool => preg_match('/^55[1-9][0-9]{9,10}$/D', $p) === 1)));
        if ($phones === []) {
            return [];
        }
        $existing = DB::table('whatsapp_phone_numbers')->whereIn('e164', $phones)
            ->orderByDesc('contacts_count')->get(['id', 'e164', 'status', 'contacts_count']);
        // EC_WA_V89_1_PHPSTAN_SAFE: garantir shape completo antes de acrescentar aprovações.
        /** @var array<string, array{phone_id:int|null,exists:bool,status:string,contacts_count:int,approved_here:bool}> $result */
        $result = [];
        foreach ($phones as $phone) {
            $result[$phone] = ['phone_id' => null, 'exists' => false, 'status' => 'unknown',
                'contacts_count' => 0, 'approved_here' => false];
        }
        // EC_WA_V89_2_INVENTORY_SHAPE: todas as entradas têm os mesmos campos.
        foreach ($existing as $row) {
            $phone = (string) data_get($row, 'e164');
            if (! array_key_exists($phone, $result)) {
                continue;
            }
            if ($result[$phone]['phone_id'] !== null) {
                continue;
            }
            $result[$phone] = [
                'phone_id' => (int) data_get($row, 'id'),
                'exists' => true,
                'status' => (string) data_get($row, 'status', 'unknown'),
                'contacts_count' => (int) data_get($row, 'contacts_count'),
                'approved_here' => false,
            ];
        }
        if (preg_match('/^(hubspot|local):([1-9][0-9]{0,17})$/D', $targetKey, $parts) === 1) {
            $approved = DB::table(self::APPROVALS)->where('target_type', $parts[1])
                ->where('target_id', (int) $parts[2])->whereIn('phone_e164', $phones)
                ->pluck('phone_e164')->all();
            foreach ($approved as $phone) {

                $approvedPhone = (string) $phone;
                if (array_key_exists($approvedPhone, $result)) {
                    $result[$approvedPhone]['approved_here'] = true;
                }
            }
        }

        return $result;
    }

    /**
     * A seleção usa E164, não IDs arbitrários de fontes. Somente aceita telefones
     * efetivamente encontrados na pesquisa concluída indicada pelo gestor.
     *
     * @param  list<string>  $phones
     * @return array{created:int,already:int,total:int}
     */
    public function approve(User $actor, string $companyName, string $searchKey, int $runId, string $targetKey, array $phones, ?string $note = null): array
    {
        abort_unless($actor->isCommercialManager(), 403);
        $research = app(WhatsappCompanyQuickResearchV88::class);
        if ($research->keyFor(trim($companyName)) !== $searchKey) {
            throw ValidationException::withMessages(['wa89_selection' => 'A pesquisa foi alterada. Pesquise a empresa novamente antes de salvar.']);
        }
        if (preg_match('/^(hubspot|local):([1-9][0-9]{0,17})$/D', $targetKey, $match) !== 1) {
            throw ValidationException::withMessages(['wa89_target' => 'Escolha o cadastro correto da empresa antes de salvar.']);
        }
        $targets = $this->targets($companyName);
        $selectedTarget = null;
        foreach ($targets as $candidate) {
            if ($candidate['key'] === $targetKey) {
                $selectedTarget = $candidate;
                break;
            }
        }
        if ($selectedTarget === null) {
            throw ValidationException::withMessages(['wa89_target' => 'Esse cadastro não corresponde à pesquisa. Escolha uma empresa listada.']);
        }
        if (count($phones) < 1 || count($phones) > 120) {
            throw ValidationException::withMessages(['wa89_selection' => 'Selecione de 1 a 120 telefones encontrados nessa pesquisa.']);
        }
        // Validação real da entrada Livewire, sem depender apenas do PHPDoc.
        if (Validator::make(['phones' => $phones], [
            'phones.*' => ['required', 'string'],
        ])->fails()) {
            throw ValidationException::withMessages(['wa89_selection' => 'A seleção contém um formato inesperado.']);
        }
        $phones = array_values(array_unique($phones));
        foreach ($phones as $phone) {
            $info = BrazilPhoneNumber::inspect($phone);
            if ($info === null || $info['e164'] !== $phone || ! in_array($info['kind'], ['mobile', 'landline'], true)) {
                throw ValidationException::withMessages(['wa89_selection' => 'Há um número inválido na seleção. Refaça a pesquisa.']);
            }
        }
        $note = mb_substr(trim((string) $note), 0, 300);

        return DB::transaction(function () use ($actor, $match, $phones, $runId, $searchKey, $selectedTarget, $note): array {
            // Mesma trava do WhatsappPhoneRegistry::sync: não perder vínculos numa sincronização concorrente.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(27041, 917)');
            }
            $run = DB::table('whatsapp_company_web_runs_v87')->where('id', $runId)
                ->where('target_key', $searchKey)->lockForUpdate()->first(['id', 'status']);
            if ($run === null || $run->status !== 'completed') {
                throw ValidationException::withMessages(['wa89_selection' => 'A pesquisa ainda não está concluída. Aguarde o resultado antes de aprovar.']);
            }
            $findings = DB::table(self::FINDINGS)->where('run_id', $runId)
                ->whereIn('phone_e164', $phones)
                ->orderByRaw("CASE WHEN kind = 'explicit_whatsapp_link' THEN 0 ELSE 1 END")
                ->orderBy('id')->get(['id', 'phone_e164']);
            $unique = $findings->unique('phone_e164');
            if ($unique->count() !== count($phones)) {
                throw ValidationException::withMessages(['wa89_selection' => 'A seleção contém números que não pertencem a essa pesquisa.']);
            }
            $when = now('UTC');
            $created = 0;
            $already = 0;
            $linkedIds = [];
            // O scan_id existente é reaproveitado até a próxima sincronização, que o atualiza.
            $scanId = (string) (DB::table('whatsapp_phone_sources')->value('scan_id') ?? Str::uuid());
            foreach ($unique as $finding) {
                $phone = (string) data_get($finding, 'phone_e164');
                $inserted = DB::table(self::APPROVALS)->insertOrIgnore([
                    'finding_id' => (int) data_get($finding, 'id'),
                    'target_type' => $match[1],
                    'target_id' => (int) $match[2],
                    'company_label' => $selectedTarget['name'],
                    'phone_e164' => $phone,
                    'note' => $note !== '' ? $note : null,
                    'approved_by' => $actor->id,
                    'approved_at' => $when,
                    'created_at' => $when, 'updated_at' => $when,
                ]);
                if ($inserted === 1) {
                    $created++;
                } else {
                    $already++;
                }
                $approval = DB::table(self::APPROVALS)
                    ->where('target_type', $match[1])->where('target_id', (int) $match[2])
                    ->where('phone_e164', $phone)->first(['id']);
                if ($approval === null) {
                    throw new \RuntimeException('Aprovação não encontrada após a inserção.');
                }
                $phoneInfo = BrazilPhoneNumber::inspect($phone);
                if ($phoneInfo === null || $phoneInfo['e164'] === null) {
                    throw new \RuntimeException('Número normalizado desapareceu.');
                }
                // Reusar o telefone canônico, ou um E164 já cadastrado por
                // migração anterior. Não duplicar registros nem sobrescrever status.
                $phoneId = DB::table('whatsapp_phone_numbers')
                    ->where('phone_key', $phoneInfo['key'])->value('id');
                if ($phoneId === null) {
                    $phoneId = DB::table('whatsapp_phone_numbers')->where('e164', $phone)
                        ->orderByDesc('contacts_count')->value('id');
                }
                if ($phoneId === null) {
                    DB::table('whatsapp_phone_numbers')->insertOrIgnore([
                        'phone_key' => $phoneInfo['key'], 'digits' => $phoneInfo['digits'],
                        'e164' => $phoneInfo['e164'], 'kind' => $phoneInfo['kind'],
                        'last_scanned_at' => $when, 'created_at' => $when, 'updated_at' => $when,
                    ]);
                    $phoneId = DB::table('whatsapp_phone_numbers')
                        ->where('phone_key', $phoneInfo['key'])->value('id');
                }
                if ($phoneId === null) {
                    throw new \RuntimeException('Telefone não encontrado após a inserção.');
                }
                $linkedIds[(int) $phoneId] = true;
                DB::table('whatsapp_phone_sources')->upsert([[
                    'phone_id' => (int) $phoneId,
                    'source_key' => 'web-approved-v89:'.(int) data_get($approval, 'id'),
                    'origin' => 'public', 'phone_field' => 'phone', 'raw_digits' => $phone,
                    'contact_label' => 'Canal público aprovado pelo gestor',
                    'company_label' => $selectedTarget['name'], 'scan_id' => $scanId,
                    'created_at' => $when, 'updated_at' => $when,
                ]], ['source_key', 'phone_field'],
                    ['phone_id', 'origin', 'raw_digits', 'company_label', 'scan_id', 'updated_at']);
            }
            foreach (array_keys($linkedIds) as $phoneId) {
                $counts = DB::table('whatsapp_phone_sources')->where('phone_id', $phoneId)
                    ->selectRaw('COUNT(*) AS occurrences')
                    ->selectRaw('COUNT(DISTINCT source_key) AS contacts')->first();
                DB::table('whatsapp_phone_numbers')->where('id', $phoneId)->update([
                    'contacts_count' => (int) ($counts->contacts ?? 0),
                    'occurrences_count' => (int) ($counts->occurrences ?? 0),
                    'updated_at' => $when,
                ]);
            }

            return ['created' => $created, 'already' => $already, 'total' => count($phones)];
        }, 3);
    }
}
