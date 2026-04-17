<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

// Payment webhook (public - no auth required for PayMongo callbacks)
Route::post('/webhooks/payments', [PaymentController::class, 'webhook'])
    ->name('payments.webhook');

// Payment routes for authenticated users
Route::middleware(['auth'])->group(function () {
    // Payment page
    Route::get('/payments/{booking}', [PaymentController::class, 'show'])
        ->name('payments.show');
    
    // GCash payment
    Route::post('/payments/{booking}/gcash', [PaymentController::class, 'processGCash'])
        ->name('payments.gcash');
    
    // Cash payment (instructor/admin only)
    Route::post('/payments/{booking}/cash', [PaymentController::class, 'markAsPaid'])
        ->name('payments.cash')
        ->middleware(['can:update,booking']);
    
    // Receipt download
    Route::get('/payments/{booking}/receipt', [PaymentController::class, 'downloadReceipt'])
        ->name('payments.receipt');
    
    // Refund (admin only)
    Route::post('/payments/{booking}/refund', [PaymentController::class, 'refund'])
        ->name('payments.refund')
        ->middleware(['role:admin']);
    
    // Payment success/cancel callbacks
    Route::get('/payments/{booking}/success', [PaymentController::class, 'success'])
        ->name('payments.success');
    Route::get('/payments/{booking}/cancel', [PaymentController::class, 'cancel'])
        ->name('payments.cancel');
});

// Mock payment routes (development only)
if (app()->environment('local', 'development')) {
    Route::get('/mock-checkout/{transactionId}', [PaymentController::class, 'mockCheckout'])
        ->name('payments.mock-checkout');
    Route::post('/mock-checkout/{transactionId}/process', [PaymentController::class, 'processMockPayment'])
        ->name('payments.mock-process');
}

// Include main web routes
require __DIR__.'/web.php';
