<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrdersController;

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/orders/initiatepayment', [OrdersController::class, 'initializePayment'])->name('api.orders.initiate_payment');
    Route::post('/orders/setuppaymenttoken', [OrdersController::class, 'setupPaymentToken'])->name('api.orders.setup_payment_token');

    Route::post('/orders/createcardsession', [OrdersController::class, 'createCyberSourceMicroformSession'])->name('api.orders.setup_cybersource_session');
});