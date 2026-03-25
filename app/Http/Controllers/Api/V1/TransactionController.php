<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\CurrencyService;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        protected CurrencyService $currencyService
    ) {}

    public function index(Request $request)
    {
        $query = $request->user()->transactions()->with(['category', 'tags']);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        if ($request->filled('tag_id')) {
            $query->whereHas('tags', function ($q) use ($request) {
                $q->where('tags.id', $request->tag_id);
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'ilike', "%{$search}%")
                  ->orWhere('notes', 'ilike', "%{$search}%");

                if (is_numeric(str_replace(['.', ','], ['', '.'], $search))) {
                    $numericVal = (float) str_replace(['.', ','], ['', '.'], $search);
                    $q->orWhereBetween('amount_brl', [$numericVal - 0.01, $numericVal + 0.01]);
                }
            });
        }

        $transactions = $query->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($transactions);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:income,expense,investment',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|max:3',
            'date' => 'required|date',
            'category_id' => 'nullable|exists:categories,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:tags,id',
            'notes' => 'nullable|string',
        ]);

        $currency = $validated['currency'] ?? 'BRL';
        $currencyData = $this->resolveCurrency($validated['amount'], $currency);

        $transaction = $request->user()->transactions()->create([
            'type' => $validated['type'],
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'currency' => $currency,
            'exchange_rate' => $currencyData['exchange_rate'],
            'amount_brl' => $currencyData['amount_brl'],
            'date' => $validated['date'],
            'category_id' => $validated['category_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        if (!empty($validated['tag_ids'])) {
            $transaction->tags()->sync($validated['tag_ids']);
        }

        $transaction->load(['category', 'tags']);

        return response()->json($transaction, 201);
    }

    public function show(Request $request, $id)
    {
        $transaction = $request->user()->transactions()
            ->with(['category', 'tags'])
            ->findOrFail($id);

        return response()->json($transaction);
    }

    public function update(Request $request, $id)
    {
        $transaction = $request->user()->transactions()->findOrFail($id);

        $validated = $request->validate([
            'type' => 'required|in:income,expense,investment',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|max:3',
            'date' => 'required|date',
            'category_id' => 'nullable|exists:categories,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:tags,id',
            'notes' => 'nullable|string',
        ]);

        $currency = $validated['currency'] ?? 'BRL';
        $currencyData = $this->resolveCurrency($validated['amount'], $currency);

        $transaction->update([
            'type' => $validated['type'],
            'description' => $validated['description'],
            'amount' => $validated['amount'],
            'currency' => $currency,
            'exchange_rate' => $currencyData['exchange_rate'],
            'amount_brl' => $currencyData['amount_brl'],
            'date' => $validated['date'],
            'category_id' => $validated['category_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        if (array_key_exists('tag_ids', $validated)) {
            $transaction->tags()->sync($validated['tag_ids'] ?? []);
        }

        $transaction->load(['category', 'tags']);

        return response()->json($transaction);
    }

    public function destroy(Request $request, $id)
    {
        $transaction = $request->user()->transactions()->findOrFail($id);

        $transaction->delete();

        return response()->json(['message' => 'Transaction deleted']);
    }

    private function resolveCurrency(float $amount, string $currency): array
    {
        if (strtoupper($currency) === 'BRL') {
            return [
                'exchange_rate' => null,
                'amount_brl' => $amount,
            ];
        }

        $result = $this->currencyService->convert($amount, $currency, 'BRL');

        return [
            'exchange_rate' => $result['rate'],
            'amount_brl' => $result['converted'] ?? $amount,
        ];
    }
}
