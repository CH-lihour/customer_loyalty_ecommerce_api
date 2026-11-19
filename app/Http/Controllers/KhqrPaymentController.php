<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A pretend KHQR (Bakong) payment for demos. No bank is involved and nothing is charged.
 *
 * It follows the shape of the real Bakong flow so a real integration can replace it later: the shop makes a
 * KHQR string for the amount, the customer scans it, and the shop checks the payment by the string's MD5
 * until it is paid or the code expires. Here the customer presses "Simulate payment" instead of paying.
 * Payments live in the cache only, because the API does not store orders yet.
 */
class KhqrPaymentController extends Controller
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PAID = 'PAID';

    public const STATUS_EXPIRED = 'EXPIRED';

    /**
     * ISO 4217 numeric codes KHQR uses for each currency.
     */
    private const CURRENCY_CODES = ['USD' => '840', 'KHR' => '116'];

    /**
     * Make a KHQR code for an amount the signed-in customer is about to pay.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt('Could not create the KHQR code.', function () use ($request): JsonResponse {
            $fields = $request->validate([
                'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
                'currency' => ['nullable', Rule::in(array_keys(self::CURRENCY_CODES))],
            ]);

            $currency = $fields['currency'] ?? 'USD';
            $amount = $currency === 'KHR'
                ? (string) max(1, (int) round((float) $fields['amount']))
                : number_format((float) $fields['amount'], 2, '.', '');
            $createdAt = now();
            $expiresAt = $createdAt->copy()->addSeconds((int) config('services.khqr.lifetime'));
            $billNumber = 'KS'.$createdAt->format('ymdHis').random_int(10, 99);
            $qr = $this->payload($amount, $currency, $billNumber, $createdAt, $expiresAt);

            $payment = [
                'md5' => md5($qr),
                'qr' => $qr,
                'customer_id' => $request->user()->id,
                'amount' => $amount,
                'currency' => $currency,
                'bill_number' => $billNumber,
                'merchant_name' => config('services.khqr.merchant_name'),
                'status' => self::STATUS_PENDING,
                'created_at' => $createdAt->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
                'paid_at' => null,
                'transaction_id' => null,
            ];
            $this->save($payment);

            return response()->json([
                'success' => true,
                'message' => 'KHQR code created. This is a demo, so no money moves.',
                'data' => $this->present($payment),
            ], 201);
        });
    }

    /**
     * Check a KHQR payment by its MD5, as the shop would poll Bakong.
     */
    public function show(Request $request, string $md5): JsonResponse
    {
        return $this->attempt('Could not check the KHQR payment.', function () use ($request, $md5): JsonResponse {
            return response()->json([
                'success' => true,
                'message' => 'KHQR payment retrieved successfully',
                'data' => $this->present($this->find($request, $md5)),
            ]);
        });
    }

    /**
     * Pretend the customer paid the code from their banking app. Paying twice keeps the first payment.
     */
    public function simulate(Request $request, string $md5): JsonResponse
    {
        abort_unless(config('services.khqr.simulate'), 404);

        return $this->attempt('Could not simulate the KHQR payment.', function () use ($request, $md5): JsonResponse {
            $payment = $this->find($request, $md5);

            if ($this->status($payment) === self::STATUS_EXPIRED) {
                throw ValidationException::withMessages(['md5' => 'This KHQR code has expired. Make a new one to pay.']);
            }

            if ($payment['status'] !== self::STATUS_PAID) {
                $payment['status'] = self::STATUS_PAID;
                $payment['paid_at'] = now()->toIso8601String();
                $payment['transaction_id'] = Str::lower(Str::random(16));
                $this->save($payment);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment received (simulated).',
                'data' => $this->present($payment),
            ]);
        });
    }

    /**
     * Load the signed-in customer's payment, or answer 404 for anyone else's.
     *
     * @return array<string, mixed>
     */
    private function find(Request $request, string $md5): array
    {
        $payment = Cache::get($this->key($md5));
        abort_if(! is_array($payment) || $payment['customer_id'] !== $request->user()->id, 404, 'KHQR payment not found.');

        return $payment;
    }

    /**
     * Keep a payment until an hour after its code expires, so a late status check still finds it.
     *
     * @param  array<string, mixed>  $payment
     */
    private function save(array $payment): void
    {
        Cache::put($this->key($payment['md5']), $payment, Carbon::parse($payment['expires_at'])->addHour());
    }

    private function key(string $md5): string
    {
        return 'khqr-payment:'.$md5;
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function status(array $payment): string
    {
        if ($payment['status'] === self::STATUS_PAID) {
            return self::STATUS_PAID;
        }

        return now()->greaterThanOrEqualTo(Carbon::parse($payment['expires_at'])) ? self::STATUS_EXPIRED : self::STATUS_PENDING;
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    private function present(array $payment): array
    {
        return [
            ...collect($payment)->except('customer_id')->all(),
            'status' => $this->status($payment),
        ];
    }

    /**
     * Build a dynamic KHQR string (an EMVCo merchant-presented QR, as Bakong defines it) ending in its CRC.
     */
    private function payload(string $amount, string $currency, string $billNumber, Carbon $createdAt, Carbon $expiresAt): string
    {
        $payload = $this->field('00', '01')
            .$this->field('01', '12')
            .$this->field('29', $this->field('00', (string) config('services.khqr.account_id')))
            .$this->field('52', '5999')
            .$this->field('53', self::CURRENCY_CODES[$currency])
            .$this->field('54', $amount)
            .$this->field('58', 'KH')
            .$this->field('59', Str::limit((string) config('services.khqr.merchant_name'), 25, ''))
            .$this->field('60', Str::limit((string) config('services.khqr.merchant_city'), 15, ''))
            .$this->field('62', $this->field('01', $billNumber))
            .$this->field('99', $this->field('00', (string) $createdAt->getTimestampMs()).$this->field('01', (string) $expiresAt->getTimestampMs()))
            .'6304';

        return $payload.$this->crc($payload);
    }

    private function field(string $tag, string $value): string
    {
        return $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /**
     * CRC-16/CCITT-FALSE, the checksum an EMVCo QR string ends with.
     */
    private function crc(string $data): string
    {
        $crc = 0xFFFF;

        foreach (str_split($data) as $character) {
            $crc ^= ord($character) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
