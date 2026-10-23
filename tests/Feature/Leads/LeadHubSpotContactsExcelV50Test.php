<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Services\HubSpotContactExportV50Service;
use App\Services\LeadGlobalStageExcelV45Service;
use App\Services\LeadUnifiedExcelV23Service;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** @return array<string, string> */
function v50ReadExcelRow(StreamedResponse $response): array
{
    ob_start();
    try {
        $response->sendContent();
        $bytes = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    expect(substr($bytes, 0, 2))->toBe('PK');

    $path = tempnam(sys_get_temp_dir(), 'v50-xlsx-');
    if ($path === false) {
        throw new RuntimeException('Não foi possível criar arquivo XLSX temporário de teste.');
    }

    $spreadsheet = null;
    try {
        file_put_contents($path, $bytes);
        $spreadsheet = (new Xlsx)->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        expect($sheet->getHighestColumn())->toBe('U')
            ->and($sheet->getHighestDataRow())->toBe(2)
            ->and($sheet->getCell('L1')->getValue())->toBe('Nome do contato')
            ->and($sheet->getCell('M1')->getValue())->toBe('Telefones')
            ->and($sheet->getCell('N1')->getValue())->toBe('E-mails')
            ->and($sheet->getCell('U1')->getValue())->toBe('Última atividade HubSpot');

        /** @var array<string, string> $cells */
        $cells = [];
        foreach (range('A', 'U') as $column) {
            $cells[$column] = (string) ($sheet->getCell($column.'2')->getValue() ?? '');
        }

        return $cells;
    } finally {
        $spreadsheet?->disconnectWorksheets();
        @unlink($path);
    }
}

function v50Contact(string $id, string $first, string $last, string $email, string $phone, string $mobile): HubSpotContact
{
    return HubSpotContact::query()->create([
        'hubspot_id' => 'v50-'.$id,
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'phone' => $phone,
        'mobile_phone' => $mobile,
        'job_title' => 'Comercial',
    ]);
}

it('exports associated contact names and communication data without duplicating fiscal companies', function (): void {
    $fiscal = Company::query()->create([
        'cnpj_root' => '98501001',
        'corporate_name' => 'V50 Empresa Fiscal',
    ]);
    Establishment::query()->create([
        'company_id' => $fiscal->id,
        'cnpj' => '98501001000100',
        'order_number' => '0001',
        'check_digits' => '00',
        'type' => 'matrix',
        'registration_status' => 'ATIVA',
        'state' => 'PR',
        'email' => 'empresa@exemplo.test',
        'phone_1' => '4444444444',
    ]);
    $hub = HubSpotCompany::query()->create([
        'hubspot_id' => 'v50-hub-fiscal',
        'company_id' => $fiscal->id,
        'match_source' => 'manual_verified',
        'name' => 'V50 HubSpot Fiscal',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v50-deal-fiscal',
        'stage_label' => 'Prospects',
        'name' => 'V50 Negócio fiscal',
    ]);
    $deal->companies()->attach($hub->id, ['is_primary' => true]);
    $leticia = v50Contact('leticia-fiscal', 'Letícia', 'Silva', 'leticia@exemplo.test', '(44) 3333-4444', '(44) 98888-0001');
    $marcio = v50Contact('marcio-fiscal', 'Márcio', 'Oliveira', 'marcio@exemplo.test', '', '(44) 97777-0002');
    $hub->contacts()->attach($leticia->id, ['is_primary' => true]);
    $deal->contacts()->attach([$leticia->id, $marcio->id]);

    $cells = v50ReadExcelRow(app(LeadUnifiedExcelV23Service::class)->excel(collect([$fiscal]), collect()));

    expect($cells['A'])->toBe('V50 Empresa Fiscal')
        ->and($cells['B'])->toBe('98501001000100')
        ->and($cells['L'])->toContain('Letícia Silva', 'Márcio Oliveira')
        ->and(substr_count($cells['L'], 'Letícia Silva'))->toBe(1)
        ->and($cells['M'])->toContain('Empresa: 4444444444', 'Letícia Silva: (44) 3333-4444 / (44) 98888-0001', 'Márcio Oliveira: (44) 97777-0002')
        ->and($cells['N'])->toContain('Empresa: empresa@exemplo.test', 'Letícia Silva: leticia@exemplo.test', 'Márcio Oliveira: marcio@exemplo.test');
});

it('exports named people from unlinked CRM companies and their associated deals without guessing CNPJ', function (): void {
    $hub = HubSpotCompany::query()->create([
        'hubspot_id' => 'v50-hub-only',
        'name' => 'V50 Empresa CRM sem CNPJ',
        'phone' => '(11) 3333-1111',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v50-deal-only',
        'name' => 'V50 Negócio sem vínculo fiscal',
        'stage_label' => 'Reunião Agendada',
    ]);
    $hub->deals()->attach($deal->id, ['is_primary' => true]);
    $leticia = v50Contact('leticia-crm', 'Letícia', 'Ferreira', 'leticia.crm@exemplo.test', '', '(11) 99999-8888');
    $marcio = v50Contact('marcio-crm', 'Márcio', 'Santos', 'marcio.crm@exemplo.test', '(11) 3222-1111', '');
    $hub->contacts()->attach($leticia->id);
    $deal->contacts()->attach($marcio->id);

    $row = app(LeadUnifiedExcelV23Service::class)->hubSpotRow($hub);
    expect($row['Nome do contato'])->toContain('Letícia Ferreira', 'Márcio Santos')
        ->and($row['E-mails'])->toContain('Letícia Ferreira: leticia.crm@exemplo.test', 'Márcio Santos: marcio.crm@exemplo.test');

    $cells = v50ReadExcelRow(app(LeadUnifiedExcelV23Service::class)->excel(collect(), collect([$hub])));
    expect($cells['A'])->toBe('V50 Empresa CRM sem CNPJ')
        ->and($cells['B'])->toBe('')
        ->and($cells['L'])->toContain('Letícia Ferreira', 'Márcio Santos')
        ->and($cells['M'])->toContain('Empresa: (11) 3333-1111', 'Letícia Ferreira: (11) 99999-8888', 'Márcio Santos: (11) 3222-1111')
        ->and($cells['N'])->toContain('Letícia Ferreira: leticia.crm@exemplo.test', 'Márcio Santos: marcio.crm@exemplo.test');
});

it('exports only the main-company contacts plus directly linked contacts for an individual HubSpot deal', function (): void {
    $main = HubSpotCompany::query()->create(['hubspot_id' => 'v50-main', 'name' => 'Empresa Principal']);
    $secondary = HubSpotCompany::query()->create(['hubspot_id' => 'v50-secondary', 'name' => 'Outra empresa']);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v50-deal-stage',
        'name' => 'Reunião de acompanhamento V50',
        'stage_label' => 'Reunião Realizada',
    ]);
    $deal->companies()->attach($main->id, ['is_primary' => true]);
    $deal->companies()->attach($secondary->id, ['is_primary' => false]);
    $leticia = v50Contact('primary', 'Letícia', 'Principal', 'leticia.principal@exemplo.test', '', '11911111111');
    $marcio = v50Contact('deal', 'Márcio', 'Do Negócio', 'marcio.negocio@exemplo.test', '1133333333', '');
    $unrelated = v50Contact('other', 'Pessoa', 'Secundária', 'secundaria@exemplo.test', '', '11922222222');
    $main->contacts()->attach($leticia->id);
    $secondary->contacts()->attach($unrelated->id);
    $deal->contacts()->attach($marcio->id);

    $cells = v50ReadExcelRow(app(LeadGlobalStageExcelV45Service::class)->excel(collect([$deal]), 'Reunião Realizada'));
    expect($cells['A'])->toBe('Empresa Principal')
        ->and($cells['R'])->toBe('v50-deal-stage')
        ->and($cells['S'])->toBe('Reunião Realizada')
        ->and($cells['L'])->toContain('Letícia Principal', 'Márcio Do Negócio')
        ->and($cells['L'])->not->toContain('Pessoa Secundária')
        ->and($cells['N'])->toContain('Letícia Principal: leticia.principal@exemplo.test', 'Márcio Do Negócio: marcio.negocio@exemplo.test')
        ->and($cells['N'])->not->toContain('secundaria@exemplo.test');
});

it('does not invent a contact and neutralizes spreadsheet formulas in contact names', function (): void {
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v50-no-contacts',
        'name' => 'Negócio sem contatos associados',
        'stage_label' => 'Prospects',
    ]);
    $empty = v50ReadExcelRow(app(LeadGlobalStageExcelV45Service::class)->excel(collect([$deal])));
    expect($empty['L'])->toBe('')->and($empty['M'])->toBe('')->and($empty['N'])->toBe('');

    $contact = v50Contact('formula', '=2+2', '', 'formula@exemplo.test', '', '');
    $deal->contacts()->attach($contact->id);
    $withContact = v50ReadExcelRow(app(LeadGlobalStageExcelV45Service::class)->excel(collect([$deal])));
    expect($withContact['L'])->toBe("'=2+2")
        ->and($withContact['N'])->toContain('=2+2: formula@exemplo.test');
});

it('deduplicates repeated HubSpot contact associations by original contact identifier', function (): void {
    $company = HubSpotCompany::query()->create(['hubspot_id' => 'v50-dedup', 'name' => 'Empresa de duplicidade']);
    $deal = HubSpotDeal::query()->create(['hubspot_id' => 'v50-dedup-deal', 'stage_label' => 'Prospects']);
    $deal->companies()->attach($company->id, ['is_primary' => true]);
    $contact = v50Contact('same', 'Letícia', 'Única', 'unicidade@exemplo.test', '', '');
    $company->contacts()->attach($contact->id);
    $deal->contacts()->attach($contact->id);

    $people = app(HubSpotContactExportV50Service::class)->forHubSpotDeal($deal, $company);
    expect($people['names'])->toBe('Letícia Única')
        ->and(substr_count($people['details'], 'Letícia Única'))->toBe(1);
});
