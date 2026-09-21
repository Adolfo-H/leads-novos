<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Cada carga proveniente do HubSpot.
         *
         * Permite auditoria e rollback lógico
         * das importações.
         */
        Schema::create(
            'hubspot_import_runs',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->uuid('uuid')
                    ->unique();

                $table
                    ->string(
                        'source',
                        40
                    )
                    ->default('export');

                $table
                    ->string(
                        'status',
                        30
                    )
                    ->default('pending')
                    ->index();

                $table
                    ->jsonb('source_files')
                    ->nullable();

                $table
                    ->jsonb('stats')
                    ->nullable();

                $table
                    ->timestampTz(
                        'started_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'finished_at'
                    )
                    ->nullable();

                $table
                    ->text('error')
                    ->nullable();

                $table->timestampsTz();
            }
        );

        /*
         * Catálogo dinâmico dos pipelines
         * e etapas encontrados no HubSpot.
         *
         * O export atual fornece os labels.
         * IDs poderão ser complementados
         * posteriormente através da API.
         */
        Schema::create(
            'hubspot_pipeline_stages',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->string(
                        'pipeline_id',
                        100
                    )
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'pipeline_label',
                        150
                    );

                $table
                    ->string(
                        'stage_id',
                        100
                    )
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'stage_label',
                        150
                    );

                $table
                    ->unsignedInteger(
                        'display_order'
                    )
                    ->nullable();

                $table
                    ->boolean('is_closed')
                    ->default(false);

                $table
                    ->boolean('is_closed_won')
                    ->default(false);

                $table
                    ->jsonb('raw_properties')
                    ->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'pipeline_label',
                        'stage_label',
                    ],
                    'hubspot_pipeline_stage_label_unique'
                );
            }
        );

        /*
         * Espelho das EMPRESAS existentes
         * no HubSpot.
         *
         * company_id é opcional:
         * somente será preenchido quando
         * conseguirmos identificar com
         * segurança o CNPJ / empresa local.
         */
        Schema::create(
            'hubspot_companies',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId(
                        'hubspot_import_run_id'
                    )
                    ->nullable()
                    ->constrained(
                        'hubspot_import_runs'
                    )
                    ->nullOnDelete();

                $table
                    ->foreignId(
                        'company_id'
                    )
                    ->nullable()
                    ->constrained(
                        'companies'
                    )
                    ->nullOnDelete();

                $table
                    ->string(
                        'hubspot_id',
                        100
                    )
                    ->unique();

                $table
                    ->string(
                        'name'
                    )
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'domain'
                    )
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'lifecycle_stage',
                        100
                    )
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'lead_status',
                        100
                    )
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'owner_name'
                    )
                    ->nullable();

                $table
                    ->string(
                        'city'
                    )
                    ->nullable();

                $table
                    ->string(
                        'state',
                        150
                    )
                    ->nullable();

                $table
                    ->string(
                        'phone',
                        80
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'last_activity_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'hubspot_created_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'hubspot_updated_at'
                    )
                    ->nullable();

                $table
                    ->jsonb(
                        'raw_properties'
                    )
                    ->nullable();

                $table->timestampsTz();

                $table->index([
                    'company_id',
                    'hubspot_id',
                ]);
            }
        );

        /*
         * Um negócio é uma entidade própria.
         *
         * NÃO existe unique company_id aqui,
         * pois uma empresa pode possuir
         * vários negócios.
         */
        Schema::create(
            'hubspot_deals',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId(
                        'hubspot_import_run_id'
                    )
                    ->nullable()
                    ->constrained(
                        'hubspot_import_runs'
                    )
                    ->nullOnDelete();

                $table
                    ->foreignId(
                        'hubspot_pipeline_stage_id'
                    )
                    ->nullable()
                    ->constrained(
                        'hubspot_pipeline_stages'
                    )
                    ->nullOnDelete();

                $table
                    ->string(
                        'hubspot_id',
                        100
                    )
                    ->unique();

                $table
                    ->string('name')
                    ->nullable();

                $table
                    ->string(
                        'pipeline_id',
                        100
                    )
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'pipeline_label',
                        150
                    )
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'stage_id',
                        100
                    )
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'stage_label',
                        150
                    )
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'owner_name'
                    )
                    ->nullable();

                $table
                    ->decimal(
                        'amount',
                        18,
                        2
                    )
                    ->nullable();

                $table
                    ->string(
                        'currency',
                        10
                    )
                    ->nullable();

                $table
                    ->boolean(
                        'is_closed'
                    )
                    ->default(false)
                    ->index();

                $table
                    ->boolean(
                        'is_closed_won'
                    )
                    ->default(false)
                    ->index();

                $table
                    ->timestampTz(
                        'hubspot_created_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'closed_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'last_activity_at'
                    )
                    ->nullable();

                $table
                    ->jsonb(
                        'raw_properties'
                    )
                    ->nullable();

                $table->timestampsTz();

                $table->index([
                    'pipeline_label',
                    'stage_label',
                ]);
            }
        );

        /*
         * Contatos do HubSpot.
         */
        Schema::create(
            'hubspot_contacts',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId(
                        'hubspot_import_run_id'
                    )
                    ->nullable()
                    ->constrained(
                        'hubspot_import_runs'
                    )
                    ->nullOnDelete();

                $table
                    ->string(
                        'hubspot_id',
                        100
                    )
                    ->unique();

                $table
                    ->string(
                        'first_name'
                    )
                    ->nullable();

                $table
                    ->string(
                        'last_name'
                    )
                    ->nullable();

                $table
                    ->string(
                        'email'
                    )
                    ->nullable()
                    ->index();

                $table
                    ->text(
                        'phone'
                    )
                    ->nullable();

                $table
                    ->text(
                        'mobile_phone'
                    )
                    ->nullable();

                $table
                    ->string(
                        'job_title'
                    )
                    ->nullable();

                $table
                    ->string(
                        'company_name'
                    )
                    ->nullable();

                $table
                    ->string(
                        'owner_name'
                    )
                    ->nullable();

                $table
                    ->string(
                        'lifecycle_stage',
                        100
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'last_activity_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'hubspot_created_at'
                    )
                    ->nullable();

                $table
                    ->jsonb(
                        'raw_properties'
                    )
                    ->nullable();

                $table->timestampsTz();
            }
        );

        /*
         * Tarefas / follow-ups do HubSpot.
         */
        Schema::create(
            'hubspot_tasks',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId(
                        'hubspot_import_run_id'
                    )
                    ->nullable()
                    ->constrained(
                        'hubspot_import_runs'
                    )
                    ->nullOnDelete();

                $table
                    ->string(
                        'hubspot_id',
                        100
                    )
                    ->unique();

                $table
                    ->string(
                        'title'
                    )
                    ->nullable();

                $table
                    ->string(
                        'status',
                        100
                    )
                    ->nullable()
                    ->index();

                $table
                    ->string(
                        'stage',
                        100
                    )
                    ->nullable();

                $table
                    ->string(
                        'type',
                        100
                    )
                    ->nullable();

                $table
                    ->string(
                        'assigned_to'
                    )
                    ->nullable();

                $table
                    ->boolean(
                        'is_open'
                    )
                    ->default(true)
                    ->index();

                $table
                    ->boolean(
                        'is_overdue'
                    )
                    ->default(false)
                    ->index();

                $table
                    ->timestampTz(
                        'due_at'
                    )
                    ->nullable()
                    ->index();

                $table
                    ->timestampTz(
                        'completed_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'hubspot_created_at'
                    )
                    ->nullable();

                $table
                    ->text(
                        'notes'
                    )
                    ->nullable();

                $table
                    ->jsonb(
                        'raw_properties'
                    )
                    ->nullable();

                $table->timestampsTz();
            }
        );

        /*
         * EMPRESA ↔ NEGÓCIO
         *
         * É N:N porque o próprio HubSpot
         * permite negócio associado a
         * múltiplas empresas.
         */
        Schema::create(
            'hubspot_company_deal',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId(
                        'hubspot_company_id'
                    )
                    ->constrained(
                        'hubspot_companies'
                    )
                    ->cascadeOnDelete();

                $table
                    ->foreignId(
                        'hubspot_deal_id'
                    )
                    ->constrained(
                        'hubspot_deals'
                    )
                    ->cascadeOnDelete();

                $table
                    ->boolean(
                        'is_primary'
                    )
                    ->default(false);

                $table->timestampsTz();

                $table->unique(
                    [
                        'hubspot_company_id',
                        'hubspot_deal_id',
                    ],
                    'hubspot_company_deal_unique'
                );
            }
        );

        /*
         * EMPRESA ↔ CONTATO
         */
        Schema::create(
            'hubspot_company_contact',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId(
                        'hubspot_company_id'
                    )
                    ->constrained(
                        'hubspot_companies'
                    )
                    ->cascadeOnDelete();

                $table
                    ->foreignId(
                        'hubspot_contact_id'
                    )
                    ->constrained(
                        'hubspot_contacts'
                    )
                    ->cascadeOnDelete();

                $table
                    ->boolean(
                        'is_primary'
                    )
                    ->default(false);

                $table->timestampsTz();

                $table->unique(
                    [
                        'hubspot_company_id',
                        'hubspot_contact_id',
                    ],
                    'hubspot_company_contact_unique'
                );
            }
        );

        /*
         * NEGÓCIO ↔ CONTATO
         */
        Schema::create(
            'hubspot_deal_contact',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId(
                        'hubspot_deal_id'
                    )
                    ->constrained(
                        'hubspot_deals'
                    )
                    ->cascadeOnDelete();

                $table
                    ->foreignId(
                        'hubspot_contact_id'
                    )
                    ->constrained(
                        'hubspot_contacts'
                    )
                    ->cascadeOnDelete();

                $table->timestampsTz();

                $table->unique(
                    [
                        'hubspot_deal_id',
                        'hubspot_contact_id',
                    ],
                    'hubspot_deal_contact_unique'
                );
            }
        );

        /*
         * TAREFA ↔ EMPRESA
         */
        Schema::create(
            'hubspot_company_task',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId(
                        'hubspot_company_id'
                    )
                    ->constrained(
                        'hubspot_companies'
                    )
                    ->cascadeOnDelete();

                $table
                    ->foreignId(
                        'hubspot_task_id'
                    )
                    ->constrained(
                        'hubspot_tasks'
                    )
                    ->cascadeOnDelete();

                $table->timestampsTz();

                $table->unique(
                    [
                        'hubspot_company_id',
                        'hubspot_task_id',
                    ],
                    'hubspot_company_task_unique'
                );
            }
        );

        /*
         * TAREFA ↔ NEGÓCIO
         */
        Schema::create(
            'hubspot_deal_task',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId(
                        'hubspot_deal_id'
                    )
                    ->constrained(
                        'hubspot_deals'
                    )
                    ->cascadeOnDelete();

                $table
                    ->foreignId(
                        'hubspot_task_id'
                    )
                    ->constrained(
                        'hubspot_tasks'
                    )
                    ->cascadeOnDelete();

                $table->timestampsTz();

                $table->unique(
                    [
                        'hubspot_deal_id',
                        'hubspot_task_id',
                    ],
                    'hubspot_deal_task_unique'
                );
            }
        );

        /*
         * TAREFA ↔ CONTATO
         */
        Schema::create(
            'hubspot_contact_task',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId(
                        'hubspot_contact_id'
                    )
                    ->constrained(
                        'hubspot_contacts'
                    )
                    ->cascadeOnDelete();

                $table
                    ->foreignId(
                        'hubspot_task_id'
                    )
                    ->constrained(
                        'hubspot_tasks'
                    )
                    ->cascadeOnDelete();

                $table->timestampsTz();

                $table->unique(
                    [
                        'hubspot_contact_id',
                        'hubspot_task_id',
                    ],
                    'hubspot_contact_task_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'hubspot_contact_task'
        );

        Schema::dropIfExists(
            'hubspot_deal_task'
        );

        Schema::dropIfExists(
            'hubspot_company_task'
        );

        Schema::dropIfExists(
            'hubspot_deal_contact'
        );

        Schema::dropIfExists(
            'hubspot_company_contact'
        );

        Schema::dropIfExists(
            'hubspot_company_deal'
        );

        Schema::dropIfExists(
            'hubspot_tasks'
        );

        Schema::dropIfExists(
            'hubspot_contacts'
        );

        Schema::dropIfExists(
            'hubspot_deals'
        );

        Schema::dropIfExists(
            'hubspot_companies'
        );

        Schema::dropIfExists(
            'hubspot_pipeline_stages'
        );

        Schema::dropIfExists(
            'hubspot_import_runs'
        );
    }
};
