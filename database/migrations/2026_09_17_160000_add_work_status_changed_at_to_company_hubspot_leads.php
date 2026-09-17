<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'company_hubspot_leads',
            function (Blueprint $table): void {
                $table
                    ->timestamp(
                        'work_status_changed_at'
                    )
                    ->nullable()
                    ->after(
                        'work_status'
                    );

                $table->index(
                    'work_status_changed_at'
                );
            }
        );

        /*
         * Leads anteriores à migration não possuem
         * o instante exato da mudança.
         *
         * Usamos o melhor marco conhecido sem
         * inventar uma data histórica.
         */
        DB::table(
            'company_hubspot_leads'
        )
            ->whereNull(
                'work_status_changed_at'
            )
            ->update([
                'work_status_changed_at' => DB::raw(
                    'COALESCE(status_synced_at, synced_at, created_at)'
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table(
            'company_hubspot_leads',
            function (Blueprint $table): void {
                $table->dropIndex([
                    'work_status_changed_at',
                ]);

                $table->dropColumn(
                    'work_status_changed_at'
                );
            }
        );
    }
};
