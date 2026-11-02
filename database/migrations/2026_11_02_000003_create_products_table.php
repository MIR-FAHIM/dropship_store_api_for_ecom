<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30)->default('resellerbrain'); // 'resellerbrain' or 'custom'
            $table->unsignedBigInteger('rb_product_id')->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->string('sku', 100)->nullable()->index();
            $table->decimal('wholesale_price', 10, 2)->default(0.00); // What dropshipper pays ResellerBrain
            $table->decimal('retail_price', 10, 2)->default(0.00);    // What customer pays on this store
            $table->decimal('compare_at_price', 10, 2)->nullable();   // Slashed discount price (e.g. 1500 -> 1200)
            $table->integer('stock')->default(0);
            $table->boolean('is_in_stock')->default(true);
            $table->decimal('weight', 8, 2)->default(0.00);
            $table->longText('description')->nullable();
            $table->string('primary_image', 500)->nullable();
            $table->json('gallery_images')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'is_active']);
            $table->index(['is_featured', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
