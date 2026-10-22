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
        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reward_id')->constrained();
            $table->foreignId('variant_id')->nullable()->constrained('reward_variants')->nullOnDelete();
            $table->string('batch')->nullable();
            $table->string('serial')->nullable();
            $table->integer('expected')->default(0);
            $table->integer('counted')->nullable();
            $table->integer('system_at_post')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocktake_lines');
    }
};
