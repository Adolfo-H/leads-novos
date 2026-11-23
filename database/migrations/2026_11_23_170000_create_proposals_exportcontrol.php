<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_proposal_numbering', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('next_number')->default(1000);
            $table->unsignedBigInteger('last_confirmed')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable();
            $table->timestamps();
        });
        DB::table('ec_proposal_numbering')->insert([
            'id' => 1, 'next_number' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('ec_proposal_price_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 30);
            $table->string('version', 100);
            $table->date('effective_from');
            $table->json('configuration');
            $table->boolean('approved')->default(false);
            $table->foreignId('approved_by')->nullable();
            $table->timestamps();
            $table->unique(['service', 'version']);
            $table->index(['service', 'approved', 'effective_from'], 'ec_price_service_date');
        });
        Schema::create('ec_proposal_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 100)->unique();
            $table->date('effective_from');
            $table->decimal('percentage', 9, 4);
            $table->text('reason');
            $table->foreignId('approved_by')->nullable();
            $table->timestamps();
        });
        Schema::create('ec_proposal_tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 100)->unique();
            $table->date('effective_from');
            $table->json('rates');
            $table->boolean('approved')->default(false);
            $table->foreignId('approved_by')->nullable();
            $table->timestamps();
        });
        Schema::create('ec_proposal_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 30)->unique();
            $table->string('name', 160);
            $table->string('source_file', 180)->nullable();
            $table->text('description')->nullable();
            $table->text('commercial_notes')->nullable();
            $table->boolean('ready_to_issue')->default(false);
            $table->timestamps();
        });
        Schema::create('ec_proposals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('origin', 20)->default('new');
            $table->string('status', 30)->default('draft')->index();
            $table->unsignedSmallInteger('revision')->default(0);
            $table->foreignId('parent_id')->nullable()->constrained('ec_proposals')->nullOnDelete();
            $table->string('legacy_code', 180)->nullable()->unique();
            $table->json('service_codes')->nullable();
            $table->json('proposal_numbers')->nullable();
            $table->json('accepted_services')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('price_date')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->string('client_name', 220);
            $table->string('client_contact', 160)->nullable();
            $table->string('client_email', 200)->nullable();
            $table->string('client_cnpj', 30)->nullable();
            $table->string('client_logo_path', 255)->nullable();
            $table->foreignId('company_id')->nullable()->index();
            $table->foreignId('hubspot_company_id')->nullable()->index();
            $table->foreignId('hubspot_deal_id')->nullable()->index();
            $table->unsignedInteger('recurring_total')->nullable();
            $table->date('recurring_from')->nullable();
            $table->date('recurring_to')->nullable();
            $table->unsignedInteger('retro_total')->nullable();
            $table->date('retro_from')->nullable();
            $table->date('retro_to')->nullable();
            $table->string('license_status', 40)->nullable();
            $table->string('retro_deadline', 180)->nullable();
            $table->unsignedSmallInteger('validity_days')->default(30);
            $table->unsignedSmallInteger('payment_days')->default(10);
            $table->string('sales_responsible', 160)->nullable();
            $table->text('notes')->nullable();
            $table->json('price_snapshot')->nullable();
            $table->decimal('monthly_final', 14, 2)->nullable();
            $table->decimal('one_time_final', 14, 2)->nullable();
            $table->text('issue_error')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->text('acceptance_evidence')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->index(['client_name', 'issued_on'], 'ec_proposal_client_date');
        });
        Schema::create('ec_proposal_number_ledger', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('number')->unique();
            $table->foreignId('proposal_id')->constrained('ec_proposals')->restrictOnDelete();
            $table->string('service', 30);
            $table->string('source', 20);
            $table->timestamps();
        });
        Schema::create('ec_proposal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id')->constrained('ec_proposals')->cascadeOnDelete();
            $table->string('kind', 35);
            $table->string('service', 30)->nullable();
            $table->string('storage_path', 500)->unique();
            $table->string('original_name', 255);
            $table->string('sha256', 64)->index();
            $table->unsignedBigInteger('bytes');
            $table->string('mime', 80);
            $table->timestamps();
            $table->index(['proposal_id', 'kind']);
        });
        Schema::create('ec_proposal_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id')->constrained('ec_proposals')->cascadeOnDelete();
            $table->string('event', 50);
            $table->json('metadata')->nullable();
            $table->foreignId('actor_id')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach ([
            'ec_proposal_events', 'ec_proposal_documents', 'ec_proposal_number_ledger',
            'ec_proposals', 'ec_proposal_templates', 'ec_proposal_tax_profiles',
            'ec_proposal_adjustments', 'ec_proposal_price_versions', 'ec_proposal_numbering',
        ] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
