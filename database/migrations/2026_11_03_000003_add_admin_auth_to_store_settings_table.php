<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            $table->string('admin_password', 255)->nullable()->after('whatsapp_number');
            $table->string('admin_token', 255)->nullable()->after('admin_password');
        });

        // Set default admin password "admin123" for any existing stores
        \Illuminate\Support\Facades\DB::table('store_settings')
            ->whereNull('admin_password')
            ->update([
                'admin_password' => Hash::make('admin123'),
            ]);
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            $table->dropColumn(['admin_password', 'admin_token']);
        });
    }
};
