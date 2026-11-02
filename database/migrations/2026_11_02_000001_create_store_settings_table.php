<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 191)->default('My Dropship Store');
            $table->string('store_tagline', 255)->default('সেরা মানের পণ্য ও দ্রুত ডেলিভারি');
            $table->string('store_phone', 30)->default('01700000000');
            $table->string('store_email', 100)->nullable();
            $table->string('store_address', 255)->nullable();
            $table->text('announcement_text')->nullable()->default('সারাদেশে হোম ডেলিভারি | ক্যাশ অন ডেলিভারি সুবিধা!');
            $table->string('logo_url', 500)->nullable();
            $table->string('banner_url', 500)->nullable();
            $table->string('primary_color', 20)->default('#2563eb');
            $table->decimal('delivery_inside_dhaka', 8, 2)->default(70.00);
            $table->decimal('delivery_outside_dhaka', 8, 2)->default(130.00);
            
            // ResellerBrain Partner API Integration
            $table->string('rb_api_key', 100)->nullable();
            $table->string('rb_api_secret', 150)->nullable();
            $table->string('rb_base_url', 255)->default('http://127.0.0.1:8000/api/partner/v1');
            $table->decimal('default_markup_amount', 8, 2)->default(250.00);
            $table->string('default_markup_type', 20)->default('fixed'); // 'fixed' or 'percentage'
            $table->boolean('auto_forward_orders')->default(true);
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
    }
};
