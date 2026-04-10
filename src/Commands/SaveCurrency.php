<?php

namespace Livewirez\Billing\Commands;

use Exception;
use App\Models\BillingCurrencyConversionRate;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Livewirez\Billing\CurrencyIngestionService;

class SaveCurrency extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'currency:save';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Saves currency records to the database using an optimized service.';

    /**
     * Execute the console command.
     *
     * @param CurrencyIngestionService $currencyIngestionService
     */
    public function handle(CurrencyIngestionService $currencyIngestionService)
    {
        $default_currency = config('billing.default_currency', 'USD');
        $active_currency_api = config('billing.currencies.active_currency_api');

        try {
            $this->components->info("Starting currency data ingestion...");

            // Call the service to handle the ingestion logic
            $currencyIngestionService->ingestCurrencyRates($active_currency_api, $default_currency);

            $this->components->info("Currency records saved and cached successfully!");

        } catch (Exception $e) {
            $this->components->error("Failed to save currency records: " . $e->getMessage());
        }
    }
}
