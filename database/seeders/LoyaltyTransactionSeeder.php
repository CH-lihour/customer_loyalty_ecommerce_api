<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use Illuminate\Database\Seeder;

class LoyaltyTransactionSeeder extends Seeder
{
    /**
     * Each demo history, oldest first: [days ago, type, points, reason].
     *
     * @var list<list<array{0: int, 1: string, 2: int, 3: string}>>
     */
    private const HISTORIES = [
        [
            [40, LoyaltyTransaction::TYPE_EARN, 320, 'Order #1001 completed'],
            [28, LoyaltyTransaction::TYPE_REFERRAL, 100, 'Referred a friend'],
            [21, LoyaltyTransaction::TYPE_EARN, 280, 'Order #1014 completed'],
            [14, LoyaltyTransaction::TYPE_SPEND, -200, 'Redeemed $2 Discount Voucher'],
            [6, LoyaltyTransaction::TYPE_EARN, 150, 'Order #1032 completed'],
        ],
        [
            [35, LoyaltyTransaction::TYPE_EARN, 900, 'Order #1006 completed'],
            [18, LoyaltyTransaction::TYPE_SPEND, -500, 'Redeemed $5 Discount Voucher'],
            [16, LoyaltyTransaction::TYPE_REFUND, 500, 'Refund: $5 Discount Voucher cancelled'],
            [3, LoyaltyTransaction::TYPE_EARN, 420, 'Order #1041 completed'],
        ],
        [
            [30, LoyaltyTransaction::TYPE_EARN, 150, 'Order #1009 completed'],
            [12, LoyaltyTransaction::TYPE_SPEND, -150, 'Redeemed Free Shipping Voucher'],
            [2, LoyaltyTransaction::TYPE_EARN, 75, 'Order #1045 completed'],
        ],
    ];

    /**
     * Seed a points history for the demo customers, once. Each row goes through the same ledger entry as live
     * activity, so the balances chain and end at the customer's balance.
     */
    public function run(): void
    {
        if (LoyaltyTransaction::exists()) {
            return;
        }

        $demoCustomer = Customer::where('email', 'customer@example.com')->first();
        $others = Customer::query()
            ->where('email', 'like', '%@example.%')
            ->where('email', '!=', 'customer@example.com')
            ->orderBy('id')
            ->limit(count(self::HISTORIES) - 1)
            ->get();

        foreach ($others->prepend($demoCustomer)->filter()->values() as $index => $customer) {
            foreach (self::HISTORIES[$index] as [$daysAgo, $type, $points, $reason]) {
                $customer->recordPoints($type, $points, $reason)
                    ->forceFill(['created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)])
                    ->save();
            }
        }
    }
}
