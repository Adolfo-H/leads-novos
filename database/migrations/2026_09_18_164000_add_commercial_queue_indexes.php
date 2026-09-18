<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Filtros por carteira / vendedor.
         */
        Schema::table(
            'company_lead_work_states',
            function (Blueprint $table): void {
                $table->index(
                    'assigned_user_id',
                    'lead_work_states_assigned_user_idx'
                );
            }
        );

        /*
         * A maioria das filas usa:
         *
         * status + vencimento da próxima tarefa.
         */
        Schema::table(
            'company_hubspot_leads',
            function (Blueprint $table): void {
                $table->index(
                    [
                        'work_status',
                        'last_task_due_at',
                    ],
                    'hubspot_leads_work_due_idx'
                );
            }
        );

        /*
         * Distribuição automática seleciona
         * elegíveis priorizando maior score.
         */
        Schema::table(
            'company_sdr_scores',
            function (Blueprint $table): void {
                $table->index(
                    [
                        'is_eligible',
                        'score',
                    ],
                    'sdr_eligible_score_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'company_lead_work_states',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'lead_work_states_assigned_user_idx'
                );
            }
        );

        Schema::table(
            'company_hubspot_leads',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'hubspot_leads_work_due_idx'
                );
            }
        );

        Schema::table(
            'company_sdr_scores',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'sdr_eligible_score_idx'
                );
            }
        );
    }
};
