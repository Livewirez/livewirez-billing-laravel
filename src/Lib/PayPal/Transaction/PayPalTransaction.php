<?php

namespace Livewirez\Billing\Lib\PayPal\Transaction;

use Livewirez\Billing\Interfaces\TransactionInterface;

readonly class PayPalTransaction implements \JsonSerializable, TransactionInterface  
{
    private string $id;
    private string $status;
    private PaymentSource $paymentSource;

    /**
     * Purchase Units
     * 
     * @var array<int, PurchaseUnit>
     */
    private array $purchaseUnits;
    private Payer $payer;

    /**
     * Links
     * 
     * @var array<int, Link>
     */
    private array $links;

    public function __construct(string $id, string $status, PaymentSource $paymentSource, array $purchaseUnits, Payer $payer, array $links) {
        $this->id = $id;
        $this->status = $status;
        $this->paymentSource = $paymentSource;
        $this->purchaseUnits = $purchaseUnits;
        $this->payer = $payer;
        $this->links = $links;
    }

    public function getId(): string {
        return $this->id;
    }

    public function getStatus(): string {
        return $this->status;
    }

    public function getPaymentSource(): PaymentSource {
        return $this->paymentSource;
    }

    public function getPurchaseUnits(): array {
        return $this->purchaseUnits;
    }

    public function getPayer(): Payer {
        return $this->payer;
    }

    public function getLinks(): array {
        return $this->links;
    }

    public static function fromArray(array $data): self {
        $paymentSource = new PaymentSource(new PayPal(
            $data['payment_source']['paypal']['email_address'],
            $data['payment_source']['paypal']['account_id'],
            $data['payment_source']['paypal']['account_status'],
            new Name(
                $data['payment_source']['paypal']['name']['given_name'],
                $data['payment_source']['paypal']['name']['surname']
            ),
            new Address($data['payment_source']['paypal']['address']['country_code']),
            $data['payment_source']['paypal']['app_switch_eligibility']
        ));
        
        $purchaseUnits = [];
        foreach ($data['purchase_units'] as $unit) {
            $captures = [];
            foreach ($unit['payments']['captures'] as $capture) {
                $captureLinks = [];
                foreach ($capture['links'] as $link) {
                    $captureLinks[] = new Link($link['href'], $link['rel'], $link['method']);
                }
                
                $captures[] = new Capture(
                    $capture['id'],
                    $capture['status'],
                    new Amount($capture['amount']['currency_code'], $capture['amount']['value']),
                    $capture['final_capture'],
                    new SellerProtection(
                        $capture['seller_protection']['status'],
                        $capture['seller_protection']['dispute_categories']
                    ),
                    new SellerReceivableBreakdown(
                        new Amount($capture['seller_receivable_breakdown']['gross_amount']['currency_code'], $capture['seller_receivable_breakdown']['gross_amount']['value']),
                        new Amount($capture['seller_receivable_breakdown']['paypal_fee']['currency_code'], $capture['seller_receivable_breakdown']['paypal_fee']['value']),
                        new Amount($capture['seller_receivable_breakdown']['net_amount']['currency_code'], $capture['seller_receivable_breakdown']['net_amount']['value'])
                    ),
                    $captureLinks,
                    $capture['create_time'],
                    $capture['update_time']
                );
            }
            
            $payments = new Payments($captures);
            
            $purchaseUnits[] = new PurchaseUnit(
                $unit['reference_id'],
                new Shipping(
                    new ShippingName($unit['shipping']['name']['full_name']),
                    new ShippingAddress(
                        $unit['shipping']['address']['address_line_1'],
                        $unit['shipping']['address']['admin_area_2'],
                        $unit['shipping']['address']['admin_area_1'],
                        $unit['shipping']['address']['postal_code'],
                        $unit['shipping']['address']['country_code']
                    )
                ),
                $payments
            );
        }
        
        $transactionLinks = [];
        foreach ($data['links'] as $link) {
            $transactionLinks[] = new Link($link['href'], $link['rel'], $link['method']);
        }
        
        $payer = new Payer(
            new Name($data['payer']['name']['given_name'], $data['payer']['name']['surname']),
            $data['payer']['email_address'],
            $data['payer']['payer_id'],
            new Address($data['payer']['address']['country_code'])
        );
        
        return new self(
            $data['id'],
            $data['status'],
            $paymentSource,
            $purchaseUnits,
            $payer,
            $transactionLinks
        );
    }

    public static function fromJson(string $json): self {
        $data = json_decode($json, true);
        
        return self::fromArray($data);
    }

    public function jsonSerialize(): mixed
    {
        return [
           'id' => $this->getId(),
            'status' => $this->getStatus(),
            'payment_source' => $this->getPaymentSource(),
            'purchase_units' => $this->getPurchaseUnits(),
            'payer' => $this->getPayer(),
            'links' => $this->getLinks(),
        ];
    }

    public function getTransactionType(): string
    {
        return 'UNKNOWN';
    }
}