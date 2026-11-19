<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KhqrPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.khqr.account_id' => 'khmer_shop_demo@demo',
            'services.khqr.merchant_name' => 'Khmer Shop',
            'services.khqr.lifetime' => 180,
            'services.khqr.simulate' => true,
        ]);
    }

    public function test_customer_gets_a_khqr_code_for_the_amount(): void
    {
        $this->travelTo('2026-11-19 13:00:00');
        Sanctum::actingAs(Customer::factory()->create(), [], 'customer');

        $response = $this->postJson('/api/payments/khqr', ['amount' => 12.5])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.amount', '12.50')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.merchant_name', 'Khmer Shop')
            ->assertJsonPath('data.paid_at', null)
            ->assertJsonPath('data.expires_at', now()->addSeconds(180)->toIso8601String())
            ->assertJsonMissingPath('data.customer_id');

        $qr = $response->json('data.qr');
        $this->assertSame(md5($qr), $response->json('data.md5'));
        $this->assertStringStartsWith('000201010212', $qr);
        $this->assertStringContainsString('0020khmer_shop_demo@demo', $qr);
        $this->assertStringContainsString('5303840', $qr);
        $this->assertStringContainsString('540512.50', $qr);
        $this->assertStringContainsString('5802KH', $qr);
        $this->assertStringContainsString('5910Khmer Shop', $qr);
        $this->assertMatchesRegularExpression('/6304[0-9A-F]{4}$/', $qr);
        $this->assertSame(substr($qr, -4), $this->crc(substr($qr, 0, -4)));

        $this->postJson('/api/payments/khqr', ['amount' => 50000.4, 'currency' => 'KHR'])
            ->assertCreated()
            ->assertJsonPath('data.amount', '50000')
            ->assertJsonPath('data.currency', 'KHR');
    }

    public function test_amount_and_currency_are_validated_and_guests_are_refused(): void
    {
        $this->postJson('/api/payments/khqr', ['amount' => 10])->assertUnauthorized();

        Sanctum::actingAs(Customer::factory()->create(), [], 'customer');

        $this->postJson('/api/payments/khqr', ['amount' => 0, 'currency' => 'EUR'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'currency']);
    }

    public function test_simulated_payment_marks_the_code_paid_once(): void
    {
        Sanctum::actingAs(Customer::factory()->create(), [], 'customer');
        $md5 = $this->postJson('/api/payments/khqr', ['amount' => 20])->json('data.md5');

        $this->getJson("/api/payments/khqr/{$md5}")->assertOk()->assertJsonPath('data.status', 'PENDING');

        $paid = $this->postJson("/api/payments/khqr/{$md5}/simulate")
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->json('data');
        $this->assertNotNull($paid['paid_at']);
        $this->assertNotNull($paid['transaction_id']);

        $this->travel(10)->minutes();

        $this->getJson("/api/payments/khqr/{$md5}")
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.transaction_id', $paid['transaction_id']);
        $this->postJson("/api/payments/khqr/{$md5}/simulate")
            ->assertOk()
            ->assertJsonPath('data.paid_at', $paid['paid_at']);
    }

    public function test_an_expired_code_cannot_be_paid(): void
    {
        Sanctum::actingAs(Customer::factory()->create(), [], 'customer');
        $md5 = $this->postJson('/api/payments/khqr', ['amount' => 20])->json('data.md5');

        $this->travel(181)->seconds();

        $this->getJson("/api/payments/khqr/{$md5}")->assertOk()->assertJsonPath('data.status', 'EXPIRED');
        $this->postJson("/api/payments/khqr/{$md5}/simulate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['md5']);
    }

    public function test_customers_cannot_see_or_pay_each_others_codes(): void
    {
        Sanctum::actingAs(Customer::factory()->create(), [], 'customer');
        $md5 = $this->postJson('/api/payments/khqr', ['amount' => 20])->json('data.md5');

        Sanctum::actingAs(Customer::factory()->create(), [], 'customer');

        $this->getJson("/api/payments/khqr/{$md5}")->assertNotFound();
        $this->postJson("/api/payments/khqr/{$md5}/simulate")->assertNotFound();
        $this->getJson('/api/payments/khqr/'.md5('unknown'))->assertNotFound();
    }

    public function test_simulating_can_be_switched_off(): void
    {
        config(['services.khqr.simulate' => false]);
        Sanctum::actingAs(Customer::factory()->create(), [], 'customer');
        $md5 = $this->postJson('/api/payments/khqr', ['amount' => 20])->json('data.md5');

        $this->postJson("/api/payments/khqr/{$md5}/simulate")->assertNotFound();
        $this->getJson("/api/payments/khqr/{$md5}")->assertJsonPath('data.status', 'PENDING');
    }

    /**
     * An independent CRC-16/CCITT-FALSE to check the code's checksum against.
     */
    private function crc(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? ($crc << 1) ^ 0x1021 : $crc << 1;
                $crc &= 0xFFFF;
            }
        }

        return sprintf('%04X', $crc);
    }
}
