<?php

use App\Http\Controllers\AdminCustomerController;
use App\Http\Controllers\AdminOrderController;
use App\Http\Controllers\AdminRedemptionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KhqrPaymentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PointController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RedemptionController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\RewardController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TierController;
use App\Http\Controllers\UserController;
use App\Models\Order;
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
        // Dashboard and analytics numbers
        Route::get('/dashboard', [DashboardController::class, 'stats'])->middleware("can:access-page,'dashboard'");
        Route::get('/analytics', [DashboardController::class, 'stats'])->middleware("can:access-page,'analytics'");
        // Registered customers, and staff tier changes
        Route::middleware("can:access-page,'customers'")->group(function () {
            Route::get('customers', [AdminCustomerController::class, 'index']);
            Route::patch('customers/{customer}/tier', [AdminCustomerController::class, 'updateTier']);
        });
        // Catalog setup
        Route::apiResource('categories', CategoryController::class)->middleware("can:access-page,'categories'");
        Route::apiResource('products', ProductController::class)->middleware("can:access-page,'products'");
        // Loyalty setup
        Route::apiResource('tiers', TierController::class)->middleware("can:access-page,'tiers'");
        Route::apiResource('badges', BadgeController::class)->middleware("can:access-page,'badges'");
        // Points ledger: rows are only added, so it has no update or delete routes
        Route::middleware("can:access-page,'points'")->prefix('points')->group(function () {
            Route::get('transactions', [PointController::class, 'transactions']);
            Route::get('customers', [PointController::class, 'customers']);
            Route::post('adjustments', [PointController::class, 'adjust']);
        });
        // Orders: points come when an order is paid; cancelling takes them back
        Route::middleware("can:access-page,'orders'")->group(function () {
            Route::get('orders', [AdminOrderController::class, 'index']);
            Route::patch('orders/{order}/status', [AdminOrderController::class, 'updateStatus']);
        });
        // Referrals convert on the friend's first paid order; staff can also mark one converted
        Route::middleware("can:access-page,'referrals'")->group(function () {
            Route::get('referrals', [ReferralController::class, 'index']);
            Route::patch('referrals/{referral}/complete', [ReferralController::class, 'complete']);
        });
        // Reward redemptions waiting to be shipped or picked up
        Route::middleware("can:access-page,'fulfillment'")->prefix('redemptions')->group(function () {
            Route::get('/', [AdminRedemptionController::class, 'index']);
            Route::patch('{redemption}/fulfill', [AdminRedemptionController::class, 'fulfill']);
            Route::patch('{redemption}/cancel', [AdminRedemptionController::class, 'cancel']);
            Route::patch('{redemption}/extend', [AdminRedemptionController::class, 'extend']);
        });
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
});

// Customer Side
// Home-page banners, public so guests see them too
Route::get('/campaigns/banners', [CampaignController::class, 'banners']);
// Storefront catalog, public so guests can browse
Route::get('/catalog/categories', [CatalogController::class, 'categories']);
Route::get('/catalog/products', [CatalogController::class, 'products']);
Route::get('/catalog/tiers', [CatalogController::class, 'tiers']);
Route::get('/catalog/rewards', [CatalogController::class, 'rewards']);

Route::post('/register', [CustomerAuthController::class, 'register']);
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register/verify', [CustomerAuthController::class, 'verifyOtp']);
    Route::post('/register/resend', [CustomerAuthController::class, 'resendOtp']);
});
Route::post('/login', [CustomerAuthController::class, 'login']);

Route::middleware('auth:customer')->group(function () {
    // Logout
    Route::post('/logout', [CustomerAuthController::class, 'logout']);
    // Profile
    Route::get('/profile', function (Request $request) {
        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully',
            'data' => [
                ...$request->user()->only(['id', 'name', 'email', 'phone', 'referral_code', 'points_balance', 'qualifying_points', 'total_spent']),
                'tier' => $request->user()->tier?->name,
                'orders_count' => $request->user()->orders()->where('status', '!=', Order::STATUS_CANCELLED)->count(),
            ],
        ]);
    });
    // Own points history
    Route::get('/points', [PointController::class, 'history']);
    // The tier and campaign multipliers the next order earns with
    Route::get('/points/rules', [PointController::class, 'rules']);
    // Own referral code and the friends who joined with it
    Route::get('/referrals', [ReferralController::class, 'mine']);
    // Demo KHQR checkout payment: make a code, check it, and pretend to pay it
    Route::prefix('payments/khqr')->group(function () {
        Route::post('/', [KhqrPaymentController::class, 'store']);
        Route::get('{md5}', [KhqrPaymentController::class, 'show']);
        Route::post('{md5}/simulate', [KhqrPaymentController::class, 'simulate']);
    });
    // Own reward redemptions: redeeming spends points; cancelling a held one refunds them
    Route::get('/redemptions', [RedemptionController::class, 'index']);
    Route::post('/redemptions', [RedemptionController::class, 'store']);
    Route::post('/redemptions/{redemption}/cancel', [RedemptionController::class, 'cancel']);
    // Own orders: placing one with a paid KHQR code earns its points and converts a pending referral
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
});
