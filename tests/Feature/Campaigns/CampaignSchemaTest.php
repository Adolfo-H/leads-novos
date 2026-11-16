<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('keeps the campaign sequence exclusion column available after migrations', function (): void {
    Mail::fake();
    Queue::fake();

    expect(Schema::hasTable('email_campaign_recipients'))->toBeTrue()
        ->and(Schema::hasColumn('email_campaign_recipients', 'excluded_from_sequences_at'))->toBeTrue()
        ->and(Schema::hasIndex('email_campaign_recipients', 'campaign_sequence_exclusion_index'))->toBeTrue();

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
