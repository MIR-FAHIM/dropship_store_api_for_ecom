<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->string('customer_name', 191);
            $table->string('customer_phone', 25)->index();
            $table->text('shipping_address');
            $table->string('district', 100)->nullable();
            $table->string('zone', 100)->nullable();
            $table->string('area', 100)->nullable();

            $table->decimal('subtotal', 10, 2)->default(0.00);
            $table->decimal('shipping_fee', 10, 2)->default(0.00);
            $table->decimal('discount', 10, 2)->default(0.00);
            $table->decimal('total', 10, 2)->default(0.00);
            $table->decimal('total_profit', 10, 2)->default(0.00);

            $table->string('payment_method', 30)->default('cod');
            $table->string('payment_status', 30)->default('unpaid');
            $table->string('status', 30)->default('pending')->index(); // pending, processing, shipped, delivered, cancelled
            $table->text('customer_note')->nullable();

            // ResellerBrain Supplier Fulfillment Integration
            $table->boolean('has_rb_items')->default(false);
            $table->string('rb_order_number', 64)->nullable()->index();
            $table->string('rb_status', 40)->default('not_forwarded'); // not_forwarded, forwarded, failed, shipped, delivered
            $table->string('rb_consignment_id', 100)->nullable();
            $table->text('rb_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
