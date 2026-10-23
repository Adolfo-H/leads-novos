<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

it('carrega o CSS final depois do Vite sem interferir com as outras telas', function (): void {
    $head = file_get_contents(resource_path('views/partials/head.blade.php'));
    $css = file_get_contents(public_path('css/leads-gestao-v35.css'));

    expect($head)->toContain('data-ec-leads-visual="v35"')
        ->and($head)->toContain("asset('css/leads-gestao-v35.css')")
        ->and($css)->toContain('PACOTE 35')
        ->and($css)->toContain('.ec-page-shell:is(#ec35-override, .leads-rf)')
        ->and($css)->toContain('.ecgm-page:is(#ec35-override, .ecgm-v2)')
        ->and($css)->toContain('.lv13-lead-line')
        ->and($css)->toContain('.lv18-chip-label');
});

it('mantem a lista operacional autorizada e a gestao separada por papel', function (): void {
    Http::preventStrayRequests();

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    $this->actingAs($manager)->get(route('leads.index'))->assertOk();
    $this->actingAs($manager)->get(route('leads.management'))->assertOk();
    $this->actingAs($seller)->get(route('leads.management'))->assertForbidden();

    Http::assertNothingSent();
});
