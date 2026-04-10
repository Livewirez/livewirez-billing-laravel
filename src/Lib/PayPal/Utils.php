<?php 

namespace Livewirez\Billing\Lib\PayPal;

use Illuminate\Http\Client\RequestException;
use Livewirez\Billing\Lib\PayPal\Enums\ErrorMessageMode;

class Utils
{
    public static function formatErrorInfoMessages(RequestException $exception, ErrorMessageMode $mode = ErrorMessageMode::RESULT_MESSAGE)
    {
        switch($mode) {
            case ErrorMessageMode::ERROR_INFO_TITLE:
                return $exception->response->json('name', $exception->response->json('message') ?? $exception->getMessage());
            case ErrorMessageMode::ERROR_INFO_MESSAGE:

                $issue = $exception->response->json('details.0.issue', '');

                $description = $exception->response->json('details.0.description', $exception->response->json('message') ?? $exception->getMessage());

                return "{$description}:{$issue}";
            case ErrorMessageMode::RESULT_MESSAGE:
                return $exception->response->json('message') ?? $exception->getMessage() ;
            default:
                return $exception->getMessage();
        }

    } 

}