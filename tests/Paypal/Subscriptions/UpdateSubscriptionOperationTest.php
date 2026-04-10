<?php

declare(strict_types=1);

namespace Livewirez\Tests\Paypal\Subscriptions;

use PHPUnit\Framework\TestCase;

class UpdateSubscriptionOperationTest extends TestCase
{
    protected static array $allowedPathsMap = [
        '/billing_info/outstanding_balance' => 'replace',
        '/custom_id' => ['add', 'replace'],
        '/plan/billing_cycles/@sequence=={n}/pricing_scheme/fixed_price' => ['add', 'replace'],
        '/plan/billing_cycles/@sequence=={n}/pricing_scheme/tiers' => 'replace',
        '/plan/billing_cycles/@sequence=={n}/total_cycles' => 'replace',
        '/plan/payment_preferences/auto_bill_outstanding' => 'replace',
        '/plan/payment_preferences/payment_failure_threshold' => 'replace',
        '/plan/taxes/inclusive' => ['add', 'replace'],
        '/plan/taxes/percentage' => ['add', 'replace'],
        '/shipping_amount' => ['add', 'replace'],
        '/start_time' => 'replace',
        '/subscriber/shipping_address' => ['add', 'replace'],
        '/subscriber/payment_source' => 'replace',
    ];


    public function testPatchOperationsMatch(): void
    {

        $patchOperations = [
            [
                "op" => "replace",
                "path" => "/plan/billing_cycles/@sequence==1/pricing_scheme/fixed_price",
                "value" => [
                    "currency_code" => "USD",
                    "value" => "50.00"
                ]
            ],
            [
                "op" => "replace",
                "path" => "/plan/taxes/percentage",
                "value" => "10"
            ]
        ];


        // Validate allowed paths for CREATED or ACTIVE plans
       
        $updates = array_filter($patchOperations, function (array $update): bool {
            if (
                !isset($update['path']) ||
                !isset($update['op']) ||
                !array_key_exists('value', $update)
            ) {
                return false;
            }

            $path = $update['path'] ?? '';
            $op = $update['op'] ?? '';

            foreach (static::$allowedPathsMap as $allowedPath => $allowedOps) {
                // Wildcard match for @sequence=={n}
                if (str_contains($allowedPath, '@sequence==') && str_contains($path, '@sequence==')) {
                    $normalizedAllowedPath = preg_replace('/@sequence==\d+/', '@sequence=={n}', $allowedPath);
                    $normalizedPath = preg_replace('/@sequence==\d+/', '@sequence=={n}', $path);
                } else {
                    $normalizedAllowedPath = $allowedPath;
                    $normalizedPath = $path;
                }

                if ($normalizedPath === $normalizedAllowedPath) {
                    // Allow single string or array of allowed operations
                    if (is_array($allowedOps)) {
                        return in_array($op, $allowedOps);
                    }
                    return $op === $allowedOps;
                }
            }

            return false;
        });

        $this->assertSame($patchOperations, $updates);
    }

    public function testPatchOperationsWillBeEmpty(): void
    {

        $patchOperations = [
            [
                "op" => "move",
                "path" => "/plan/billing_cycles/@sequence=={34}/pricing_scheme/fixed_price",
                "value" => [
                    "currency_code" => "USD",
                    "value" => "50.00"
                ]
            ],
            [
                "op" => "copy",
                "path" => "/plan/taxes/percentage",
                "value" => "10"
            ]
        ];


        // Validate allowed paths for CREATED or ACTIVE plans
       
        $updates = array_filter($patchOperations, function (array $update): bool {
            if (
                !isset($update['path']) ||
                !isset($update['op']) ||
                !array_key_exists('value', $update)
            ) {
                return false;
            }

            $path = $update['path'] ?? '';
            $op = $update['op'] ?? '';

            foreach (static::$allowedPathsMap as $allowedPath => $allowedOps) {
                // Wildcard match for @sequence=={n}
                if (str_contains($allowedPath, '@sequence==') && str_contains($path, '@sequence==')) {
                    $normalizedAllowedPath = preg_replace('/@sequence==\d+/', '@sequence=={n}', $allowedPath);
                    $normalizedPath = preg_replace('/@sequence==\d+/', '@sequence=={n}', $path);
                } else {
                    $normalizedAllowedPath = $allowedPath;
                    $normalizedPath = $path;
                }

                if ($normalizedPath === $normalizedAllowedPath) {
                    // Allow single string or array of allowed operations
                    if (is_array($allowedOps)) {
                        return in_array($op, $allowedOps);
                    }
                    return $op === $allowedOps;
                }
            }

            return false;
        });

        $this->assertSame([], $updates);
    }
}