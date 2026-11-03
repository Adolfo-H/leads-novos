<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('simples_option')->nullable();
            $table->boolean('mei_option')->nullable();
            $table->string('tax_source_period', 7)->nullable();
            $table->index(['simples_option', 'mei_option'], 'companies_tax_flags_idx');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex('companies_tax_flags_idx');
            $table->dropColumn(['simples_option', 'mei_option', 'tax_source_period']);
        });
    }
};
