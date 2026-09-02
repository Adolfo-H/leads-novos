<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            // CNPJ completo sem pontuação.
            // 12 primeiras posições podem ser alfanuméricas.
            // Os 2 últimos caracteres são os dígitos verificadores numéricos.
            $table->string('cnpj', 14)->unique();

            $table->string('order_number', 4);
            $table->string('check_digits', 2);

            // matrix | branch
            $table->string('type', 20)->index();

            $table->string('fantasy_name', 255)->nullable();

            $table->string('registration_status_code', 10)->nullable()->index();
            $table->string('registration_status', 100)->nullable()->index();
            $table->date('registration_status_date')->nullable();
            $table->string('registration_status_reason_code', 10)->nullable();

            $table->date('start_date')->nullable();

            $table->string('foreign_city_name', 150)->nullable();
            $table->string('country_code', 10)->nullable();

            /*
             * Endereço
             */
            $table->string('address_type', 50)->nullable();
            $table->string('street', 255)->nullable();
            $table->string('number', 50)->nullable();
            $table->string('complement', 255)->nullable();
            $table->string('neighborhood', 150)->nullable();

            $table->string('zip_code', 20)->nullable()->index();

            $table->string('state', 2)->nullable()->index();

            $table->string('municipality_code', 20)->nullable()->index();
            $table->string('municipality_name', 150)->nullable()->index();

            /*
             * Contatos cadastrais da empresa.
             *
             * Contatos de pessoas/decisores serão armazenados
             * posteriormente em outra estrutura.
             */
            $table->string('phone_1', 50)->nullable();
            $table->string('phone_2', 50)->nullable();
            $table->string('fax', 50)->nullable();
            $table->string('email', 255)->nullable()->index();

            /*
             * Situação especial da Receita.
             */
            $table->string('special_situation', 255)->nullable();
            $table->date('special_situation_date')->nullable();

            $table->string('source', 50)->default('manual')->index();
            $table->timestampTz('source_updated_at')->nullable();

            $table->jsonb('metadata')->nullable();

            $table->timestampsTz();

            $table->index(['company_id', 'type']);
            $table->index(['state', 'municipality_name']);
            $table->index(['registration_status_code', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
