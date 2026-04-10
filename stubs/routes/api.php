<?php

use Illuminate\Http\Request;
use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\Lib\CartItem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Response;
use Livewirez\Billing\Models\BillingProduct;
use Livewirez\Billing\Http\Resources\BillingProductResource;
use Livewirez\Billing\Lib\BillingCartWrapper;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('cart')->group(function () {
        Route::post('/update', function (Request $request) {
            
            $data = $request->validate([
                'products' => ['required', 'array'],
                'products.*' => ['required', 'array', 'required_array_keys:product,quantity'],
                'products.*.product' => ['required', 'numeric', 'exists:billing_products,id'],
                'products.*.quantity' =>  ['required', 'numeric']
            ]);

            $sci = array_map(function (array $product) {
                return new CartItem(BillingProduct::find($product['product']), $product['quantity']);
            }, $data['products']);

            $request->session()->put('cart', $cart = new Cart($sci));

            $request->session()->put(
                'billing_cart',
                BillingCartWrapper::fromCart(
                    $request->user(), 
                    $cart
                )
            );

            return response()->json(['message' => 'Shopping Cart Updated Successfully']);
        })->name('api.cart.update');

        Route::get('/sync', function (Request $request) {
            $cart = $request->session()->get('cart', new Cart());

            if (! $cart) {
                return ['items' => []];
            }

            $items = collect($cart->all())
            ->map(fn (CartItem $sci) => [
                'product' => BillingProductResource::make($sci->getProduct())->resolve($request),
                'quantity' => $sci->getQuantity()
            ]);

            return [
                'items' => $items
            ];
        })->name('api.cart.sync');

        Route::delete('/clear', function (Request $request) {
            $request->session()->forget('cart');


            $bc = $request->session()->get('billing_cart'); 
            $bc?->deleteCart();
            $request->session()->forget('billing_cart'); 

            return [
                'items' => []
            ];
        })->name('api.cart.clear');
    });
});