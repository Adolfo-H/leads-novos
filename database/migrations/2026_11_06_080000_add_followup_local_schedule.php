<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaign_sequences', function (Blueprint $table): void {
            // NULL nos planos existentes = preservar o intervalo historico, sem disparos involuntarios.
            $table->string('send_time_local', 5)->nullable();
            // Data opcional para a PRIMEIRA rodada de continuidade (Y-m-d, horario local).
            $table->date('first_send_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_sequences', function (Blueprint $table): void {
            $table->dropColumn(['send_time_local', 'first_send_date']);
        });
    }
};
