<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            // Domain uniquely identifies each client store (e.g. jashorebro.resellerbrain.com)
            $table->string('domain', 255)->nullable()->unique()->after('id');
            $table->string('whatsapp_number', 30)->nullable()->after('store_phone');
            $table->boolean('is_active')->default(true)->after('auto_forward_orders');
        });

        // Seed the existing row (id=1) with a default domain so the FK constraints work
        \Illuminate\Support\Facades\DB::table('store_settings')
            ->where('id', 1)
            ->update(['domain' => 'shops.resellerbrain.com']);
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            $table->dropColumn(['domain', 'whatsapp_number', 'is_active']);
        });
    }
};
