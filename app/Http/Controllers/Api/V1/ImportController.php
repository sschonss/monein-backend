<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\PicPayExtractParser;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function picpay(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf|max:10240',
        ]);

        $parser = new PicPayExtractParser();
        $parsed = $parser->parse($request->file('file')->getPathname());

        if (empty($parsed)) {
            return response()->json(['message' => 'Nenhuma transação encontrada no PDF'], 422);
        }

        $user = $request->user();

        // Separate global account transactions
        $regularTransactions = [];
        $globalTransactions = [];
        $globalReturnTransactions = [];

        foreach ($parsed as $tx) {
            if (!empty($tx['is_global_account'])) {
                $globalTransactions[] = $tx;
            } elseif (!empty($tx['is_global_account_return'])) {
                $globalReturnTransactions[] = $tx;
            } else {
                $regularTransactions[] = $tx;
            }
        }

        // Ensure categories exist for regular + return transactions
        $allRegular = array_merge($regularTransactions, $globalReturnTransactions);
        $categoryMap = $this->ensureCategories($user, $allRegular);

        // Import regular transactions
        $imported = 0;
        $skipped = 0;

        foreach (array_merge($regularTransactions, $globalReturnTransactions) as $tx) {
            $exists = $user->transactions()
                ->where('date', $tx['date'])
                ->where('description', $tx['description'])
                ->where('amount', $tx['amount'])
                ->where('type', $tx['type'])
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            $isReturn = !empty($tx['is_global_account_return']);

            $user->transactions()->create([
                'type' => $isReturn ? 'income' : $tx['type'],
                'description' => $tx['description'],
                'amount' => $tx['amount'],
                'currency' => 'BRL',
                'exchange_rate' => null,
                'amount_brl' => $tx['amount'],
                'date' => $tx['date'],
                'category_id' => $categoryMap[$tx['category_name']] ?? null,
                'notes' => ($isReturn ? 'Resgate Conta Global - ' : '') . 'Importado do extrato PicPay - ' . $tx['time'],
            ]);

            $imported++;
        }

        $response = [
            'message' => 'Importação concluída',
            'imported' => $imported,
            'skipped' => $skipped,
            'total' => count($parsed),
        ];

        // Include global account transactions for user review
        if (!empty($globalTransactions)) {
            $response['pending_global'] = array_map(function ($tx) {
                return [
                    'date' => $tx['date'],
                    'time' => $tx['time'],
                    'description' => $tx['description'],
                    'amount' => $tx['amount'],
                ];
            }, $globalTransactions);
        }

        return response()->json($response);
    }

    public function confirmGlobal(Request $request)
    {
        $request->validate([
            'transactions' => 'required|array|min:1',
            'transactions.*.date' => 'required|date',
            'transactions.*.time' => 'required|string',
            'transactions.*.description' => 'required|string',
            'transactions.*.amount' => 'required|numeric|min:0.01',
            'transactions.*.currency' => 'required|in:USD,EUR',
        ]);

        $user = $request->user();

        // Ensure "Conta Global" category exists
        $category = Category::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('is_default', true);
        })->where('name', 'Conta Global')->first();

        if (!$category) {
            $category = Category::create([
                'name' => 'Conta Global',
                'type' => 'investment',
                'color' => '#475569',
                'icon' => 'globe',
                'user_id' => $user->id,
            ]);
        }

        $imported = 0;
        $skipped = 0;
        $addedByCurrency = ['USD' => 0, 'EUR' => 0];

        foreach ($request->transactions as $tx) {
            $exists = $user->transactions()
                ->where('date', $tx['date'])
                ->where('amount', $tx['amount'])
                ->where('type', 'investment')
                ->where('category_id', $category->id)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            $user->transactions()->create([
                'type' => 'investment',
                'description' => $tx['description'],
                'amount' => $tx['amount'],
                'currency' => $tx['currency'],
                'exchange_rate' => null,
                'amount_brl' => $tx['amount'],
                'date' => $tx['date'],
                'category_id' => $category->id,
                'notes' => 'Conta Global (' . $tx['currency'] . ') - Importado do extrato PicPay - ' . $tx['time'],
            ]);

            $addedByCurrency[$tx['currency']] += (float) $tx['amount'];
            $imported++;
        }

        // Auto-increment manual balances with estimated foreign currency amount
        $currencyService = new \App\Services\CurrencyService();
        foreach ($addedByCurrency as $currency => $brlAmount) {
            if ($brlAmount <= 0) continue;

            $manualBalance = \App\Models\GlobalAccountBalance::where('user_id', $user->id)
                ->where('currency', $currency)
                ->first();

            if ($manualBalance) {
                $rate = $currencyService->getExchangeRate('BRL', $currency);
                if ($rate) {
                    $foreignAmount = round($brlAmount * $rate, 2);
                    $manualBalance->balance += $foreignAmount;
                    $manualBalance->save();
                }
            }
        }

        $hasManualBalances = \App\Models\GlobalAccountBalance::where('user_id', $user->id)->exists();

        return response()->json([
            'message' => 'Transações da Conta Global importadas',
            'imported' => $imported,
            'skipped' => $skipped,
            'needs_balance_update' => $hasManualBalances && $imported > 0,
        ]);
    }

    private function ensureCategories($user, array $transactions): array
    {
        $categoryMap = [];
        $categoryNames = collect($transactions)->pluck('category_name')->unique()->toArray();

        if (empty($categoryNames)) {
            return $categoryMap;
        }

        $existingCategories = Category::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('is_default', true);
        })->whereIn('name', $categoryNames)->get()->keyBy('name');

        foreach (collect($transactions)->unique('category_name') as $tx) {
            if ($existingCategories->has($tx['category_name'])) {
                $categoryMap[$tx['category_name']] = $existingCategories[$tx['category_name']]->id;
            } else {
                $cat = Category::create([
                    'name' => $tx['category_name'],
                    'type' => $tx['category_type'],
                    'color' => $tx['category_color'],
                    'icon' => 'tag',
                    'user_id' => $user->id,
                ]);
                $categoryMap[$tx['category_name']] = $cat->id;
            }
        }

        return $categoryMap;
    }
}
