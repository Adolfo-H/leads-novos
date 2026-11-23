<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaign_sequences', function (Blueprint $table): void {
            // NULL preserva regras legadas. JSON ativa agendamento explicito por rodada.
            $table->json('send_dates')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_sequences', function (Blueprint $table): void {
            $table->dropColumn('send_dates');
        });
    }
};
