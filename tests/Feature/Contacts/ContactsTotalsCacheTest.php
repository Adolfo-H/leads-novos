<?php

use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Services\Contacts\ContactPeopleDirectory;
use Illuminate\Support\Facades\DB;

it('reutiliza totais por curto periodo e permite invalidacao explicita', function (): void {
    $directory = app(ContactPeopleDirectory::class);
    $directory->clearCachedTotals();

    try {
        $before = $directory->totalsCached();
        $contact = HubSpotContact::query()->create([
            'hubspot_id' => '24021199',
            'first_name' => 'Contato',
            'last_name' => 'Teste Cache',
        ]);

        expect($directory->totals()['all'])->toBe($before['all'] + 1)
            ->and($directory->totalsCached()['all'])->toBe($before['all']);

        $directory->clearCachedTotals();

        expect($directory->totalsCached()['all'])->toBe($before['all'] + 1)
            ->and($directory->paginate('Teste Cache')->total())->toBe(1);

        $contact->delete();
    } finally {
        $directory->clearCachedTotals();
    }
});

it('filtra negocios diretamente associados a um contato sem alterar o contador', function (): void {
    $contact = HubSpotContact::query()->create([
        'hubspot_id' => '24021200',
        'first_name' => 'Pessoa',
        'last_name' => 'Com Negocio',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => '24021201',
        'name' => 'Negocio do contato',
    ]);
    DB::table('hubspot_deal_contact')->insert([
        'hubspot_contact_id' => $contact->id,
        'hubspot_deal_id' => $deal->id,
    ]);

    $directory = app(ContactPeopleDirectory::class);
    $result = $directory->paginate('', 'deals');
    $row = collect($result->items())->firstWhere('source_key', 'hubspot:24021200');

    expect($result->total())->toBe(1)
        ->and($row)->not->toBeNull()
        ->and((int) $row->deals_count)->toBe(1);
});
