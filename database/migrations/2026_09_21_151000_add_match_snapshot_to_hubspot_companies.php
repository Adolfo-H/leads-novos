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
                        'matched_cnpj_root',
                        8
                    )
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'matched_company_name'
                    )
                    ->nullable();

                $table
                    ->string(
                        'match_source',
                        60
                    )
                    ->nullable()
                    ->index();
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'hubspot_companies',
            function (Blueprint $table): void {
                $table->dropColumn([
                    'matched_cnpj_root',
                    'matched_company_name',
                    'match_source',
                ]);
            }
        );
    }
};
