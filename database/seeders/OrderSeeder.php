<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\OrderService;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    /**
     * Seed open demo orders for the dashboards, through the same checkout customers use.
     * They stay short of completed, so seeding changes no points. Runs once.
     */
    public function run(): void
    {
        $customers = Customer::query()->orderBy('id')->take(4)->get();
        $products = Product::query()->with('category:id,is_active')->orderBy('id')->take(6)->get();

        if (Order::query()->exists() || $customers->isEmpty() || $products->isEmpty()) {
            return;
        }

        $orders = app(OrderService::class);

        foreach ($products as $index => $product) {
            $customer = $customers[$index % $customers->count()];
            $order = $orders->place(
                $customer,
                [['product_id' => $product->id, 'quantity' => $index % 3 + 1]],
                "{$index} Street 240, Daun Penh",
                '012345678',
            );
            $order->update(['status' => [Order::PENDING, Order::CONFIRMED, Order::PROCESSING][$index % 3]]);
            $order->payment()->update(['status' => Payment::PENDING]);
        }
    }
}
