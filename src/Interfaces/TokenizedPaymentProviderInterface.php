<?php

namespace Livewirez\Billing\Interfaces;

use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\PaymentResult;
use Livewirez\Billing\Models\BillingPlan;
use Livewirez\Billing\SubscriptionResult;
use Illuminate\Contracts\Encryption\Encrypter;
use Livewirez\Billing\Models\BillablePaymentMethod;
use Livewirez\Billing\Models\BillingPlanPrice;

interface TokenizedPaymentProviderInterface
{
   public function setEncrypter(Encrypter $encrypter): static;

   public function getEncrypter(): Encrypter;
   
   public function setupPaymentToken(array $data = []): array;

   public function setupSubscriptionPaymentToken(BillingPlanPrice $planPrice, array $data = []): array;

   public function completePaymentWithToken(
      Cart $cart, string $token, array $data = [] 
   ): PaymentResult;

   public function completePaymentWithSavedToken(
      Cart $cart, BillablePaymentMethod $paymentMethod, array $data = [] 
   ): PaymentResult;

   public function startSubscriptionWithToken(
      BillingPlan $plan, BillingPlanPrice $planPrice, string $token, array $data = [] 
   ): SubscriptionResult;

   public function startSubscriptionWithSavedToken(
      BillingPlan $plan, BillingPlanPrice $planPrice, BillablePaymentMethod $paymentMethod, array $data = []
   ): SubscriptionResult;
}