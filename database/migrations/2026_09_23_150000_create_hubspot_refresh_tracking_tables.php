<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'hubspot_refresh_runs',
            function (
                Blueprint $table
            ): void {
                $table->id();

                $table
                    ->foreignId(
                        'initiated_by'
                    )
                    ->nullable()
                    ->constrained(
                        'users'
                    )
                    ->nullOnDelete();

                $table
                    ->string(
                        'scope',
                        30
                    )
                    ->default(
                        'bulk'
                    );

                $table
                    ->string(
                        'status',
                        40
                    )
                    ->default(
                        'queued'
                    )
                    ->index();

                $table
                    ->unsignedInteger(
                        'total'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'processed'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'changed'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'unchanged'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'failed'
                    )
                    ->default(0);

                $table
                    ->json(
                        'summary'
                    )
                    ->nullable();

                $table
                    ->timestamp(
                        'started_at'
                    )
                    ->nullable();

                $table
                    ->timestamp(
                        'completed_at'
                    )
                    ->nullable();

                $table->timestamps();
            }
        );

        Schema::create(
            'hubspot_refresh_items',
            function (
                Blueprint $table
            ): void {
                $table->id();

                $table
                    ->foreignId(
                        'run_id'
                    )
                    ->constrained(
                        'hubspot_refresh_runs'
                    )
                    ->cascadeOnDelete();

                $table
                    ->foreignId(
                        'company_id'
                    )
                    ->constrained(
                        'companies'
                    )
                    ->cascadeOnDelete();

                $table
                    ->string(
                        'status',
                        30
                    )
                    ->default(
                        'queued'
                    )
                    ->index();

                $table
                    ->json(
                        'changes'
                    )
                    ->nullable();

                $table
                    ->text(
                        'error'
                    )
                    ->nullable();

                $table
                    ->timestamp(
                        'started_at'
                    )
                    ->nullable();

                $table
                    ->timestamp(
                        'completed_at'
                    )
                    ->nullable();

                $table->timestamps();

                $table->unique([
                    'run_id',
                    'company_id',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'hubspot_refresh_items'
        );

        Schema::dropIfExists(
            'hubspot_refresh_runs'
        );
    }
};
