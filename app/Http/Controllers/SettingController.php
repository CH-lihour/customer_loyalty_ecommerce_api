<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Change the KHR rate for one US dollar.
     */
    public function updateExchangeRate(Request $request): JsonResponse
    {
        return $this->attempt('Could not save the exchange rate.', function () use ($request): JsonResponse {
            $data = $request->validate(['rate' => ['required', 'integer', 'min:1', 'max:1000000000']]);

            Setting::put(Setting::EXCHANGE_RATE, $data['rate']);
            ActivityLog::record('EXCHANGE_RATE_UPDATED', "Changed the exchange rate to {$data['rate']} KHR", staff: $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Exchange rate saved successfully',
                'data' => ['exchange_rate' => $data['rate']],
            ]);
        });
    }

    /**
     * Change how long a redemption holds its unit.
     */
    public function updateStockSettings(Request $request): JsonResponse
    {
        return $this->attempt('Could not save the stock settings.', function () use ($request): JsonResponse {
            $data = $request->validate(['reservation_hours' => ['required', 'integer', 'min:1', 'max:720']]);

            Setting::put(Setting::RESERVATION_HOURS, $data['reservation_hours']);
            ActivityLog::record('STOCK_SETTINGS_UPDATED', "Reservation timeout set to {$data['reservation_hours']}h", staff: $request->user());

            return response()->json([
                'success' => true,
                'message' => 'Stock settings saved successfully',
                'data' => ['reservation_hours' => $data['reservation_hours']],
            ]);
        });
    }
}
