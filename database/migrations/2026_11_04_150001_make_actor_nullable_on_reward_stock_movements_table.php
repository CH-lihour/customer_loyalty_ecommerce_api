<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers and the system also move stock: redemptions reserve it and expiries release it.
     */
    public function up(): void
    {
        Schema::table('reward_stock_movements', function (Blueprint $table) {
            $table->foreignId('actor_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reward_stock_movements', function (Blueprint $table) {
            $table->foreignId('actor_id')->nullable(false)->change();
        });
    }
};
