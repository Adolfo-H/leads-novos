<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_web_probe_results', function (Blueprint $table): void {
            $table->unsignedTinyInteger('session')->nullable()->index();
        });
        Schema::table('whatsapp_web_probe_events', function (Blueprint $table): void {
            $table->unsignedTinyInteger('session')->nullable()->index();
        });

        Schema::create('whatsapp_web_scan_agents', function (Blueprint $table): void {
            $table->unsignedTinyInteger('session')->primary();
            $table->string('connection_state', 24)->default('offline');
            $table->string('account_number', 20)->nullable();
            $table->unsignedBigInteger('current_phone_id')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestampTz('last_connected_at')->nullable();
            $table->string('last_error', 200)->nullable();
            $table->timestampsTz();
        });
        $now = now('UTC');
        DB::table('whatsapp_web_scan_agents')->insert([
            ['session' => 1, 'connection_state' => 'offline', 'created_at' => $now, 'updated_at' => $now],
            ['session' => 2, 'connection_state' => 'offline', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('whatsapp_web_scan_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('session');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('requested_limit');
            $table->string('status', 24)->default('queued');
            $table->unsignedTinyInteger('processed')->default(0);
            $table->unsignedTinyInteger('found')->default(0);
            $table->unsignedTinyInteger('not_found')->default(0);
            $table->unsignedTinyInteger('inconclusive')->default(0);
            $table->unsignedTinyInteger('errors')->default(0);
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->string('error_message', 240)->nullable();
            $table->timestampsTz();
            $table->index(['session', 'status', 'id'], 'wa_web_batches_slot_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_web_scan_batches');
        Schema::dropIfExists('whatsapp_web_scan_agents');
        Schema::table('whatsapp_web_probe_events', fn (Blueprint $table) => $table->dropColumn('session'));
        Schema::table('whatsapp_web_probe_results', fn (Blueprint $table) => $table->dropColumn('session'));
    }
};
