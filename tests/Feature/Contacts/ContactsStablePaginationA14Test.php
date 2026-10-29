<?php

use App\Models\CommercialContact;
use App\Models\HubSpotContact;
use App\Services\Contacts\ContactPeopleDirectory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return list<string> */
function a14Keys(LengthAwarePaginator $page): array
{
    return array_map(static fn (stdClass $row): string => (string) $row->source_key, $page->items());
}

it('estabiliza a paginacao em caso de nomes e IDs empatados entre origens', function (): void {
    $moment = now()->startOfSecond()->subHour();

    for ($i = 1; $i <= 14; $i++) {
        HubSpotContact::query()->create([
            'hubspot_id' => (string) (880140000 + $i),
            'first_name' => 'Contato',
            'last_name' => 'Empatado',
            'email' => 'hubspot-a14-'.$i.'@example.invalid',
            'last_activity_at' => $moment,
        ]);

        $manual = CommercialContact::query()->create([
            'name' => 'Contato Empatado',
            'email' => 'manual-a14-'.$i.'@example.invalid',
        ]);
        DB::table('commercial_contacts')->where('id', $manual->id)
            ->update(['updated_at' => $moment]);
    }

    $directory = app(ContactPeopleDirectory::class);

    foreach (['name', 'recent'] as $order) {
        $combined = [];
        foreach ([1, 2, 3] as $page) {
            // Os dois caminhos precisam devolver os mesmos registros na mesma ordem.
            $ordinary = $directory->paginate('', 'all', 'all', 'all', null, $page, 12, $order, true, null, null, false);
            $optimized = $directory->paginate('', 'all', 'all', 'all', null, $page, 12, $order, true, null, null, true);

            expect($ordinary->total())->toBe(28)
                ->and($optimized->total())->toBe(28)
                ->and(a14Keys($optimized))->toBe(a14Keys($ordinary));
            $combined = [...$combined, ...a14Keys($optimized)];
        }
        expect($combined)->toHaveCount(28)
            ->and(count(array_unique($combined)))->toBe(28);
    }
});

it('nao consulta verificacoes de telefone em uma pagina sem contatos', function (): void {
    $sql = [];
    DB::listen(static function (QueryExecuted $event) use (&$sql): void {
        $sql[] = strtolower($event->sql);
    });

    $result = app(ContactPeopleDirectory::class)->paginate(
        '', 'all', 'all', 'all', null, 50, 12, 'name', true, null, null, true,
    );

    expect($result->items())->toBe([]);
    $verificationQueries = array_filter(
        $sql,
        static fn (string $query): bool => str_contains($query, 'contact_phone_verifications'),
    );
    expect($verificationQueries)->toBe([]);
});
