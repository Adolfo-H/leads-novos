<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_web_scan_settings', function (Blueprint $table): void {
            $table->string('end_time', 5)->default('18:00');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_web_scan_settings', function (Blueprint $table): void {
            $table->dropColumn('end_time');
        });
    }
};
