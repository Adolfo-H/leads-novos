<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_proposal_history_metadata', function (Blueprint $table): void {
            $table->foreignId('proposal_id')->primary()->constrained('ec_proposals')->cascadeOnDelete();
            $table->string('document_sha256', 64);
            $table->string('title', 500);
            $table->string('document_name', 255);
            $table->unsignedSmallInteger('historic_year')->nullable();
            $table->string('historic_number', 20)->nullable();
            $table->date('issued_on')->nullable();
            $table->string('responsible_name', 220)->nullable();
            $table->string('responsible_email', 320)->nullable();
            $table->string('recipient_email', 320)->nullable();
            $table->decimal('monthly_amount', 13, 2)->nullable();
            $table->decimal('one_time_amount', 13, 2)->nullable();
            $table->json('services');
            $table->text('calculation')->nullable();
            $table->string('commercial_status', 32)->index();
            $table->string('extraction_status', 32);
            $table->unsignedBigInteger('crm_company_id')->nullable();
            $table->unsignedBigInteger('crm_deal_id')->nullable();
            $table->string('crm_stage', 255)->nullable();
            $table->string('crm_match', 48);
            $table->json('evidence');
            $table->timestampTz('updated_at');
            $table->index('historic_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_proposal_history_metadata');
    }
};
