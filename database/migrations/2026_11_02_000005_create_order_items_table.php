<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->nullOnDelete();
            
            $table->string('source', 30)->default('resellerbrain');
            $table->unsignedBigInteger('rb_product_id')->nullable();

            $table->string('product_name', 255);
            $table->string('sku', 100)->nullable();
            $table->string('primary_image', 500)->nullable();

            $table->decimal('unit_price', 10, 2);      // Retail customer price
            $table->decimal('wholesale_price', 10, 2); // ResellerBrain cost price
            $table->integer('quantity')->default(1);
            $table->decimal('line_total', 10, 2);
            $table->decimal('line_profit', 10, 2);     // (unit_price - wholesale_price) * qty

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
