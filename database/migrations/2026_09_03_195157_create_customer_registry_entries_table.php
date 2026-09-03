<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'customer_registry_entries',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->string('cnpj_root', 8)
                    ->nullable()
                    ->index();

                $table
                    ->string('cnpj', 14)
                    ->nullable();

                $table->string(
                    'corporate_name'
                );

                $table
                    ->string('normalized_name')
                    ->index();

                $table
                    ->string('source', 100)
                    ->default(
                        'exportcontrol-clientes'
                    );

                $table
                    ->boolean('enabled')
                    ->default(true);

                $table
                    ->jsonb('metadata')
                    ->nullable();

                $table->timestampsTz();

                $table->index([
                    'cnpj_root',
                    'enabled',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'customer_registry_entries'
        );
    }
};
