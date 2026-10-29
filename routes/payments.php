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
        ->name('payments.gcash')
        ->middleware(['waiver.signed', 'throttle:payments']);

    // GCash receipt upload + verification. The image itself is private and is
    // streamed through an authorised route, never a public storage URL.
    Route::post('/payments/{booking}/receipt', [PaymentController::class, 'uploadReceipt'])
        ->name('payments.receipt.upload')
        ->middleware(['waiver.signed', 'throttle:payments']);

    Route::get('/payments/{booking}/receipt/image', [PaymentController::class, 'receiptImage'])
        ->name('payments.receipt.image');

    Route::post('/payments/{booking}/receipt/verify', [PaymentController::class, 'verifyReceipt'])
        ->name('payments.receipt.verify')
        ->middleware('throttle:payments');

    Route::post('/payments/{booking}/receipt/reject', [PaymentController::class, 'rejectReceipt'])
        ->name('payments.receipt.reject')
        ->middleware('throttle:payments');
    
    // Cash payment (instructor/admin only)
    Route::post('/payments/{booking}/cash', [PaymentController::class, 'markAsPaid'])
        ->name('payments.cash')
        ->middleware(['can:update,booking', 'throttle:payments']);
    
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

// Mock payment routes (development only). They must still be authenticated:
// otherwise a guest/expired session can execute a "successful" payment and only
// discover the missing session when the student-only confirmation route
// redirects to /login.
if (app()->environment('local', 'development', 'testing')) {
    Route::middleware(['auth', 'verified', 'role:student'])->group(function () {
        Route::get('/mock-checkout/{transactionId}', [PaymentController::class, 'mockCheckout'])
            ->name('payments.mock-checkout');
        Route::post('/mock-checkout/{transactionId}/process', [PaymentController::class, 'processMockPayment'])
            ->middleware('throttle:payments')
            ->name('payments.mock-process');
    });
}

// Include main web routes
require __DIR__.'/web.php';
