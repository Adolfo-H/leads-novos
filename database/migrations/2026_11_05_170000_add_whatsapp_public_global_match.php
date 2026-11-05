<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_public_site_evidences', function (Blueprint $table): void {
            // Mesmo número existente no diretório, independentemente da empresa de origem.
            // phone_id LEGADO mantém o significado de vínculo explícito à empresa do site.
            $table->foreignId('global_phone_id')->nullable()
                ->constrained('whatsapp_phone_numbers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_public_site_evidences', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('global_phone_id');
        });
    }
};
