<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CurrencyService
{
    public function getExchangeRate(string $from, string $to = 'BRL'): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $cacheKey = "exchange_rate_{$from}_{$to}";

        return Cache::remember($cacheKey, 3600, function () use ($from, $to) {
            try {
                $response = Http::get("https://api.frankfurter.dev/v1/latest", [
                    'base' => $from,
                    'symbols' => $to,
                ]);

                if ($response->successful()) {
                    return (float) $response->json("rates.{$to}");
                }

                return null;
            } catch (\Exception $e) {
                return null;
            }
        });
    }

    public function convert(float $amount, string $from, string $to = 'BRL'): array
    {
        $rate = $this->getExchangeRate($from, $to);

        return [
            'rate' => $rate,
            'converted' => $rate ? round($amount * $rate, 2) : null,
        ];
    }
}
