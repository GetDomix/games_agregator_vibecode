<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class HttpClientFactory
{
    public static function make(): PendingRequest
    {
        $timeout = (float) config('gpa.http_timeout', 20);
        $retries = max(0, (int) config('gpa.http_max_retries', 2));

        return Http::timeout($timeout)
            ->retry($retries, 350, throw: false)
            ->withHeaders([
                'User-Agent' => 'KeySignal/2.0 (+https://gpa.local; price-aggregator; contact=ops)',
                'Accept' => 'application/json, text/plain, */*',
            ]);
    }
}
