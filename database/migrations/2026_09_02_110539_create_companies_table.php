<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // As 8 primeiras posições do CNPJ.
            // Aceita números e letras para suportar o CNPJ alfanumérico.
            $table->string('cnpj_root', 8)->unique();

            $table->string('corporate_name', 255);
            $table->string('normalized_name', 255)->index();

            $table->string('legal_nature_code', 10)->nullable()->index();
            $table->string('legal_nature_description', 255)->nullable();

            $table->string('responsible_qualification_code', 10)->nullable();

            $table->decimal('share_capital', 18, 2)->nullable()->index();

            $table->string('size_code', 10)->nullable()->index();
            $table->string('size_description', 100)->nullable();

            $table->string('federative_entity', 255)->nullable();

            // Origem principal dos dados atuais.
            // Ex.: manual, receita_federal, apibrasil.
            $table->string('source', 50)->default('manual')->index();
            $table->timestampTz('source_updated_at')->nullable();

            // Espaço para informações específicas de provedores sem
            // comprometer a estrutura principal.
            $table->jsonb('metadata')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
