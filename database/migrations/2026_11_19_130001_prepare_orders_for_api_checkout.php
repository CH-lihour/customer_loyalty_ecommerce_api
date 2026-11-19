<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Checkout sends the delivery address as text, so an order no longer needs a saved
     * address, and a payment keeps the KHQR code it was paid with so one code pays for one order only.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('address_id')->nullable()->change();
            $table->string('order_number')->nullable()->unique()->after('id');
            $table->text('delivery_address')->nullable()->after('delivery_phone');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('reference')->nullable()->unique()->after('method');
            $table->string('transaction_id')->nullable()->after('reference');
            $table->string('currency', 3)->default('USD')->after('amount');
            $table->string('paid_amount')->nullable()->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'transaction_id', 'currency', 'paid_amount']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['order_number']);
            $table->dropColumn(['order_number', 'delivery_address']);
        });
    }
};
