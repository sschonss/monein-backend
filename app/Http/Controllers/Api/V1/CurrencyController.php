<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CurrencyService;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    public function __construct(
        protected CurrencyService $currencyService
    ) {}

    public function rate(Request $request)
    {
        $request->validate([
            'from' => 'required|string|max:3',
            'to' => 'sometimes|string|max:3',
        ]);

        $from = strtoupper($request->from);
        $to = strtoupper($request->input('to', 'BRL'));

        $rate = $this->currencyService->getExchangeRate($from, $to);

        if ($rate === null) {
            return response()->json(['error' => 'Unable to fetch exchange rate'], 422);
        }

        return response()->json([
            'from' => $from,
            'to' => $to,
            'rate' => $rate,
        ]);
    }
}
