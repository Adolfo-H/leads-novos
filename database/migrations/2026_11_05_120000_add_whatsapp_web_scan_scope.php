<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_web_scan_batches', function (Blueprint $table): void {
            $table->string('scan_scope', 16)->default('mobile');
        });
        Schema::table('whatsapp_web_scan_settings', function (Blueprint $table): void {
            $table->string('scan_scope', 16)->default('all');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_web_scan_settings', function (Blueprint $table): void {
            $table->dropColumn('scan_scope');
        });
        Schema::table('whatsapp_web_scan_batches', function (Blueprint $table): void {
            $table->dropColumn('scan_scope');
        });
    }
};
