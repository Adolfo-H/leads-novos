<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'hubspot_contacts',
            function (Blueprint $table): void {
                $table
                    ->text('phone')
                    ->nullable()
                    ->change();

                $table
                    ->text('mobile_phone')
                    ->nullable()
                    ->change();
            }
        );
    }

    public function down(): void
    {
        /*
         * Não reduzimos novamente os campos.
         *
         * O HubSpot pode armazenar mais de um
         * telefone, observações ou até URLs
         * nesses valores antigos.
         */
    }
};
