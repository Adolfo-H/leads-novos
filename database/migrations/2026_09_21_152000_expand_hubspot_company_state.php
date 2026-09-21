<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'hubspot_companies',
            function (Blueprint $table): void {
                $table
                    ->string(
                        'state',
                        150
                    )
                    ->nullable()
                    ->change();
            }
        );
    }

    public function down(): void
    {
        /*
         * Não reduzimos novamente para 20,
         * pois o HubSpot utiliza nomes completos
         * de estado/região e poderíamos truncar
         * dados já importados.
         */
    }
};
