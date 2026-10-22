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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tier_id')->constrained();
            $table->string('name');
            $table->string('name_kh')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('password');
            $table->string('province')->nullable();
            $table->string('avatar')->nullable();
            $table->string('referral_code')->unique();
            $table->integer('points_balance')->default(0);
            $table->integer('qualifying_points')->default(0);
            $table->decimal('total_spent', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
