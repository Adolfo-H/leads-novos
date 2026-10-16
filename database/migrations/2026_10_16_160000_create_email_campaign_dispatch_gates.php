<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma linha fixa, independente da tabela de campanhas.
        Schema::create('email_campaign_dispatch_gates', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });

        DB::table('email_campaign_dispatch_gates')->insert(['id' => 1]);

        // Auditoria de liberações manuais: não registra endereços nem senhas.
        Schema::create('email_campaign_reservation_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('recipient_id');
            $table->unsignedBigInteger('campaign_id');
            $table->string('action', 30);
            $table->string('token_fingerprint', 16)->nullable();
            $table->string('reason', 240);
            $table->timestampTz('reviewed_at');
            $table->index(['recipient_id', 'reviewed_at'], 'campaign_reservation_review_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_reservation_reviews');
        Schema::dropIfExists('email_campaign_dispatch_gates');
    }
};
