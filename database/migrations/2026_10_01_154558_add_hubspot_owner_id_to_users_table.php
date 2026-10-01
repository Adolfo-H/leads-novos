<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'users',
            function (Blueprint $table): void {
                $table
                    ->string(
                        'hubspot_owner_id',
                        100
                    )
                    ->nullable()
                    ->after(
                        'commercial_role'
                    );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'users',
            function (Blueprint $table): void {
                $table->dropColumn(
                    'hubspot_owner_id'
                );
            }
        );
    }
};
