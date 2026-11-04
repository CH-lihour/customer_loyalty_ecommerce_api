<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PointController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RedemptionController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RestockRequestController;
use App\Http\Controllers\RewardController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockItemController;
use App\Http\Controllers\StockLocationController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StocktakeController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TierController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\RecordStaffActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Staff Side
Route::prefix('backend')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        // Logout
        Route::post('/logout', [AuthController::class, 'logout']);
        // Profile, with the role and pages the frontend uses for menu access
        Route::get('/profile', function (Request $request) {
            return response()->json([
                'success' => true,
                'message' => 'Profile retrieved successfully',
                'data' => $request->user()->load('role.permissions')->toApiArray(),
            ]);
        });
        Route::patch('/profile', [AuthController::class, 'updateProfile']);

        // Changes here are added to the activity log automatically.
        Route::middleware(RecordStaffActivity::class)->group(function () {
            // Catalog setup
            Route::apiResource('categories', CategoryController::class)->middleware("can:access-page,'categories'");
            Route::apiResource('products', ProductController::class)->middleware("can:access-page,'products'");
            // Loyalty setup
            Route::apiResource('tiers', TierController::class)->middleware("can:access-page,'tiers'");
            Route::apiResource('badges', BadgeController::class)->middleware("can:access-page,'badges'");
            Route::middleware("can:access-page,'campaigns'")->group(function () {
                Route::patch('campaigns/{campaign}/status', [CampaignController::class, 'updateStatus']);
                Route::apiResource('campaigns', CampaignController::class);
            });
            Route::middleware("can:access-page,'rewards'")->group(function () {
                Route::get('rewards/tier-options', [RewardController::class, 'tierOptions']);
                Route::patch('rewards/{reward}/status', [RewardController::class, 'updateStatus']);
                Route::apiResource('rewards', RewardController::class);
            });
            // Staff accounts and role permissions (Super Admin only)
            Route::middleware('can:manage-staff')->group(function () {
                Route::apiResource('roles', RoleController::class);
                Route::apiResource('users', UserController::class)->except('destroy');
            });
        });

        // Points ledger: rows are only added, so it has no update or delete routes
        Route::middleware("can:access-page,'points'")->prefix('points')->group(function () {
            Route::get('transactions', [PointController::class, 'transactions']);
            Route::get('customers', [PointController::class, 'customers']);
            Route::post('adjustments', [PointController::class, 'adjust']);
        });
        // Customers, orders and referrals, which the dashboards also read
        Route::get('/customers', [CustomerController::class, 'index'])->middleware("can:access-any-page,'customers','users','dashboard','analytics','logs','referrals','fulfillment'");
        Route::patch('/customers/{customer}/tier', [CustomerController::class, 'updateTier'])->middleware("can:access-page,'customers'");
        Route::patch('/customers/{customer}/status', [CustomerController::class, 'updateStatus'])->middleware('can:manage-staff');
        Route::get('/orders', [OrderController::class, 'index'])->middleware("can:access-any-page,'orders','customers','dashboard','analytics'");
        Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])->middleware("can:access-page,'orders'");
        Route::get('/referrals', [ReferralController::class, 'index'])->middleware("can:access-any-page,'referrals','analytics'");
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->middleware("can:access-any-page,'logs','dashboard'");
        Route::get('/reports/summary', [ReportController::class, 'summary'])->middleware("can:access-any-page,'dashboard','analytics'");
        // System settings (Super Admin only)
        Route::put('/settings/exchange-rate', [SettingController::class, 'updateExchangeRate'])->middleware('can:manage-staff');
        // Reward stock: the ledger, stock rules, locations, suppliers, stocktakes and redemptions (any inventory page)
        Route::middleware('can:access-stock')->group(function () {
            Route::get('/stock', [StockController::class, 'index']);
            Route::get('/stock-movements', [StockMovementController::class, 'index']);
            Route::post('/redemptions/{redemption}/fulfill', [RedemptionController::class, 'fulfill']);
            Route::post('/redemptions/{redemption}/extend', [RedemptionController::class, 'extend']);

            Route::middleware(RecordStaffActivity::class)->group(function () {
                Route::post('/stock-movements', [StockMovementController::class, 'store']);
                Route::post('/stocktakes', [StocktakeController::class, 'store']);
                Route::patch('/stocktakes/{stocktake}/lines/{line}', [StocktakeController::class, 'count'])->scopeBindings();

                Route::middleware("can:manage-stock,'manager'")->group(function () {
                    Route::put('/stock-items/{reward}', [StockItemController::class, 'update']);
                    Route::apiResource('stock-locations', StockLocationController::class)->only(['store', 'update']);
                    Route::apiResource('suppliers', SupplierController::class)->only(['store', 'update']);
                    Route::post('/stocktakes/{stocktake}/post', [StocktakeController::class, 'post']);
                    Route::post('/stocktakes/{stocktake}/cancel', [StocktakeController::class, 'cancel']);
                });
            });

            Route::post('/redemptions/{redemption}/cancel', [RedemptionController::class, 'cancel'])->middleware("can:manage-stock,'manager'");
            Route::put('/stock-settings', [SettingController::class, 'updateStockSettings'])->middleware("can:manage-stock,'admin'");
        });
    });
});

// Customer Side
// What guests can browse: the catalog, rewards, tiers, badges, campaigns and the exchange rate
Route::get('/storefront', [StorefrontController::class, 'index']);
// Home-page banners, public so guests see them too
Route::get('/campaigns/banners', [CampaignController::class, 'banners']);

Route::post('/register', [CustomerAuthController::class, 'register']);
Route::post('/login', [CustomerAuthController::class, 'login']);

Route::middleware('auth:customer')->group(function () {
    // Logout
    Route::post('/logout', [CustomerAuthController::class, 'logout']);
    // Profile
    Route::get('/profile', function (Request $request) {
        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully',
            'data' => $request->user()->only(['id', 'name', 'name_kh', 'email', 'phone', 'province', 'referral_code', 'points_balance', 'qualifying_points']),
        ]);
    });
    Route::patch('/profile', [AccountController::class, 'update']);
    // Everything about the signed-in customer
    Route::get('/account', [AccountController::class, 'show']);
    // Own points history
    Route::get('/points', [PointController::class, 'history']);
    // Orders
    Route::post('/checkout', [CheckoutController::class, 'store']);
    // Rewards
    Route::post('/redemptions', [RedemptionController::class, 'store']);
    Route::post('/redemptions/{redemption}/cancel', [RedemptionController::class, 'cancelMine'])->whereNumber('redemption');
    Route::post('/restock-requests', [RestockRequestController::class, 'store']);
    Route::delete('/restock-requests/{restockRequest}', [RestockRequestController::class, 'destroy'])->whereNumber('restockRequest');
});
