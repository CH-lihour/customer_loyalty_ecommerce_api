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
        Schema::create('reward_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reward_id')->constrained();
            $table->foreignId('variant_id')->nullable()->constrained('reward_variants')->nullOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('type');
            $table->integer('on_hand_delta')->default(0);
            $table->integer('reserved_delta')->default(0);
            $table->string('batch')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('serials')->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->string('source')->nullable();
            $table->string('reference')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reward_stock_movements');
    }
};
