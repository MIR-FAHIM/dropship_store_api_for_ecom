<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add store_id to categories
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->default(1)->after('id');
            $table->foreign('store_id')->references('id')->on('store_settings')->onDelete('cascade');
            $table->index('store_id');
        });

        // Add store_id to products
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->default(1)->after('id');
            $table->foreign('store_id')->references('id')->on('store_settings')->onDelete('cascade');
            $table->index('store_id');
        });

        // Add store_id to orders
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->default(1)->after('id');
            $table->foreign('store_id')->references('id')->on('store_settings')->onDelete('cascade');
            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['store_id']);
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['store_id']);
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['store_id']);
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }
};
