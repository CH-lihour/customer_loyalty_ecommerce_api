<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reward_stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reward_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->unique();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->string('tracking');
            $table->integer('low_stock_threshold')->default(0);
            $table->integer('reorder_point')->default(0);
            $table->integer('reorder_qty')->default(0);
            $table->string('out_of_stock');
            $table->boolean('allow_backorder')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reward_stock_items');
    }
};
