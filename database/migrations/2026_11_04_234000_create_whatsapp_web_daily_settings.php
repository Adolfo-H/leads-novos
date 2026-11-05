<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_web_scan_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedTinyInteger('daily_limit')->default(30);
            $table->string('start_time', 5)->default('09:00');
            $table->boolean('auto_enabled')->default(false);
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('last_scheduled_on')->nullable();
            $table->timestampsTz();
        });
        DB::table('whatsapp_web_scan_settings')->insert([
            'id' => 1, 'daily_limit' => 30, 'start_time' => '09:00',
            'auto_enabled' => false, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_web_scan_settings');
    }
};
