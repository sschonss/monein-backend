<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InvestmentAccount;
use App\Models\InvestmentMovement;
use App\Services\CofrinhoParser;
use Illuminate\Http\Request;

class InvestmentController extends Controller
{
    public function accounts(Request $request)
    {
        $accounts = $request->user()->investmentAccounts()
            ->withCount('movements')
            ->get()
            ->map(function ($account) {
                $lastMovement = $account->movements()->orderByDesc('date')->first();
                $totals = $account->movements()->selectRaw("
                    COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposited,
                    COALESCE(SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END), 0) as total_withdrawn,
                    COALESCE(SUM(CASE WHEN type = 'yield' THEN amount ELSE 0 END), 0) as total_yield
                ")->first();

                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'source' => $account->source,
                    'current_balance' => $lastMovement ? (float) $lastMovement->balance_after : 0,
                    'total_deposited' => (float) $totals->total_deposited,
                    'total_withdrawn' => (float) $totals->total_withdrawn,
                    'total_yield' => (float) $totals->total_yield,
                    'movements_count' => $account->movements_count,
                    'last_update' => $lastMovement?->date?->format('Y-m-d'),
                ];
            });

        return response()->json($accounts);
    }

    public function show(Request $request, int $id)
    {
        $account = $request->user()->investmentAccounts()->findOrFail($id);

        $totals = $account->movements()->selectRaw("
            COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposited,
            COALESCE(SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END), 0) as total_withdrawn,
            COALESCE(SUM(CASE WHEN type = 'yield' THEN amount ELSE 0 END), 0) as total_yield
        ")->first();

        $lastMovement = $account->movements()->orderByDesc('date')->first();

        // Balance evolution (daily snapshots grouped by date)
        $balanceEvolution = $account->movements()
            ->selectRaw("date, MAX(balance_after) as balance")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($m) => [
                'date' => $m->date->format('Y-m-d'),
                'balance' => (float) $m->balance,
            ]);

        // Monthly yield aggregation
        $monthlyYield = $account->movements()
            ->where('type', 'yield')
            ->selectRaw("DATE_TRUNC('month', date) as month, SUM(amount) as total")
            ->groupByRaw("DATE_TRUNC('month', date)")
            ->orderByRaw("DATE_TRUNC('month', date)")
            ->get()
            ->map(fn ($m) => [
                'month' => \Carbon\Carbon::parse($m->month)->format('M/y'),
                'yield' => (float) $m->total,
            ]);

        // Recent movements
        $movements = $account->movements()
            ->orderByDesc('date')
            ->limit(100)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'amount' => (float) $m->amount,
                'balance_after' => (float) $m->balance_after,
                'date' => $m->date->format('Y-m-d'),
            ]);

        return response()->json([
            'id' => $account->id,
            'name' => $account->name,
            'source' => $account->source,
            'current_balance' => $lastMovement ? (float) $lastMovement->balance_after : 0,
            'total_deposited' => (float) $totals->total_deposited,
            'total_withdrawn' => (float) $totals->total_withdrawn,
            'total_yield' => (float) $totals->total_yield,
            'balance_evolution' => $balanceEvolution,
            'monthly_yield' => $monthlyYield,
            'movements' => $movements,
        ]);
    }

    public function summary(Request $request)
    {
        $user = $request->user();
        $accounts = $user->investmentAccounts()->with('movements')->get();

        $totalBalance = 0;
        $totalDeposited = 0;
        $totalWithdrawn = 0;
        $totalYield = 0;

        foreach ($accounts as $account) {
            $last = $account->movements->sortByDesc('date')->first();
            if ($last) {
                $totalBalance += (float) $last->balance_after;
            }
            foreach ($account->movements as $m) {
                match ($m->type) {
                    'deposit' => $totalDeposited += (float) $m->amount,
                    'withdrawal' => $totalWithdrawn += (float) $m->amount,
                    'yield' => $totalYield += (float) $m->amount,
                };
            }
        }

        // Include Conta Global transactions (type='investment' in transactions table)
        // Deposits: type='investment', category='Conta Global'
        $globalDeposits = $user->transactions()
            ->where('type', 'investment')
            ->selectRaw("
                currency,
                COALESCE(SUM(amount_brl), 0) as total_brl,
                COUNT(*) as count
            ")
            ->groupBy('currency')
            ->get();

        // Returns: type='income', category='Conta Global' (AstroPay withdrawals)
        $globalCategory = \App\Models\Category::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('is_default', true);
        })->where('name', 'Conta Global')->first();

        $globalReturnsBrl = 0;
        $globalSpendsBrl = 0;
        if ($globalCategory) {
            // AstroPay returns (money back to PicPay)
            $returns = $user->transactions()
                ->where('type', 'income')
                ->where('category_id', $globalCategory->id)
                ->selectRaw("COALESCE(SUM(amount_brl), 0) as total_brl")
                ->first();
            $globalReturnsBrl = (float) ($returns->total_brl ?? 0);

            // Manual spends (money spent from global account)
            $spends = $user->transactions()
                ->where('type', 'expense')
                ->where('category_id', $globalCategory->id)
                ->selectRaw("COALESCE(SUM(amount_brl), 0) as total_brl")
                ->first();
            $globalSpendsBrl = (float) ($spends->total_brl ?? 0);
        }

        $globalWithdrawnTotal = $globalReturnsBrl + $globalSpendsBrl;

        $globalDepositsBrl = 0;
        $globalByCurrency = [];
        foreach ($globalDeposits as $g) {
            $globalDepositsBrl += (float) $g->total_brl;
            $globalByCurrency[] = [
                'currency' => $g->currency,
                'total_brl' => round((float) $g->total_brl, 2),
                'count' => (int) $g->count,
            ];
        }

        // Manual balances set by user
        $manualBalances = $user->globalAccountBalances()->get();
        $manualByCurrency = [];
        foreach ($manualBalances as $mb) {
            $manualByCurrency[$mb->currency] = (float) $mb->balance;
        }

        return response()->json([
            'total_balance' => round($totalBalance, 2),
            'total_deposited' => round($totalDeposited, 2),
            'total_withdrawn' => round($totalWithdrawn, 2),
            'total_yield' => round($totalYield, 2),
            'accounts_count' => $accounts->count(),
            'global_account' => [
                'total_deposited_brl' => round($globalDepositsBrl, 2),
                'total_returned_brl' => round($globalReturnsBrl, 2),
                'total_spent_brl' => round($globalSpendsBrl, 2),
                'net_brl' => round($globalDepositsBrl - $globalWithdrawnTotal, 2),
                'by_currency' => $globalByCurrency,
                'manual_balances' => $manualByCurrency,
            ],
        ]);
    }

    public function importCofrinho(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf|max:10240',
        ]);

        $parser = new CofrinhoParser();
        $parsed = $parser->parse($request->file('file')->getPathname());

        if (empty($parsed['movements'])) {
            return response()->json(['message' => 'Nenhuma movimentação encontrada no PDF'], 422);
        }

        $user = $request->user();

        // Find or create account by name
        $account = InvestmentAccount::firstOrCreate(
            ['user_id' => $user->id, 'name' => $parsed['name']],
            ['source' => 'picpay_cofrinho']
        );

        $imported = 0;
        $skipped = 0;

        foreach ($parsed['movements'] as $movement) {
            $exists = InvestmentMovement::where('account_id', $account->id)
                ->where('date', $movement['date'])
                ->where('type', $movement['type'])
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            InvestmentMovement::create([
                'account_id' => $account->id,
                'user_id' => $user->id,
                'type' => $movement['type'],
                'amount' => $movement['amount'],
                'balance_after' => $movement['balance_after'] ?? 0,
                'date' => $movement['date'],
            ]);

            $imported++;
        }

        return response()->json([
            'message' => 'Importação concluída',
            'account_name' => $parsed['name'],
            'imported' => $imported,
            'skipped' => $skipped,
            'total' => count($parsed['movements']),
        ]);
    }

    public function globalAccount(Request $request)
    {
        $user = $request->user();
        $currency = $request->query('currency');

        // Get the Conta Global category
        $globalCategory = \App\Models\Category::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('is_default', true);
        })->where('name', 'Conta Global')->first();

        $categoryId = $globalCategory?->id;

        // Deposits (type=investment)
        $depositsQuery = $user->transactions()->where('type', 'investment');
        if ($currency) {
            $depositsQuery->where('currency', $currency);
        }
        $deposits = $depositsQuery->orderByDesc('date')->get()->map(fn ($t) => [
            'id' => $t->id,
            'date' => $t->date->format('Y-m-d'),
            'description' => $t->description,
            'amount' => (float) $t->amount,
            'amount_brl' => (float) $t->amount_brl,
            'currency' => $t->currency,
            'direction' => 'deposit',
        ]);

        // Returns (type=income, category=Conta Global - AstroPay)
        $returns = collect();
        $spends = collect();
        if ($categoryId) {
            $returns = $user->transactions()
                ->where('type', 'income')
                ->where('category_id', $categoryId)
                ->orderByDesc('date')
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'date' => $t->date->format('Y-m-d'),
                    'description' => $t->description,
                    'amount' => (float) $t->amount,
                    'amount_brl' => (float) $t->amount_brl,
                    'currency' => 'BRL',
                    'direction' => 'return',
                ]);

            // Manual spends (type=expense, category=Conta Global)
            $spends = $user->transactions()
                ->where('type', 'expense')
                ->where('category_id', $categoryId)
                ->orderByDesc('date')
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'date' => $t->date->format('Y-m-d'),
                    'description' => $t->description,
                    'amount' => (float) $t->amount,
                    'amount_brl' => (float) $t->amount_brl,
                    'currency' => $t->currency,
                    'direction' => 'spend',
                ]);
        }

        // Merge and sort by date desc
        $transactions = $deposits->concat($returns)->concat($spends)->sortByDesc('date')->values();

        // Totals by currency (deposits only)
        $totals = $user->transactions()
            ->where('type', 'investment')
            ->selectRaw("currency, COALESCE(SUM(amount_brl), 0) as total_brl, COUNT(*) as count")
            ->groupBy('currency')
            ->get()
            ->map(fn ($g) => [
                'currency' => $g->currency,
                'total_brl' => round((float) $g->total_brl, 2),
                'count' => (int) $g->count,
            ]);

        // Total withdrawn (returns + spends)
        $totalReturns = 0;
        $totalSpends = 0;
        if ($categoryId) {
            $totalReturns = (float) $user->transactions()
                ->where('type', 'income')
                ->where('category_id', $categoryId)
                ->sum('amount_brl');
            $totalSpends = (float) $user->transactions()
                ->where('type', 'expense')
                ->where('category_id', $categoryId)
                ->sum('amount_brl');
        }

        // Monthly evolution (deposits + returns)
        $monthlyDeposits = $user->transactions()
            ->where('type', 'investment')
            ->when($currency, fn ($q) => $q->where('currency', $currency))
            ->selectRaw("DATE_TRUNC('month', date) as month, SUM(amount_brl) as deposits")
            ->groupByRaw("DATE_TRUNC('month', date)")
            ->orderByRaw("DATE_TRUNC('month', date)")
            ->get()
            ->keyBy(fn ($m) => \Carbon\Carbon::parse($m->month)->format('M/y'));

        $monthlyReturns = collect();
        $monthlySpends = collect();
        if ($categoryId && !$currency) {
            $monthlyReturns = $user->transactions()
                ->where('type', 'income')
                ->where('category_id', $categoryId)
                ->selectRaw("DATE_TRUNC('month', date) as month, SUM(amount_brl) as total")
                ->groupByRaw("DATE_TRUNC('month', date)")
                ->orderByRaw("DATE_TRUNC('month', date)")
                ->get()
                ->keyBy(fn ($m) => \Carbon\Carbon::parse($m->month)->format('M/y'));

            $monthlySpends = $user->transactions()
                ->where('type', 'expense')
                ->where('category_id', $categoryId)
                ->selectRaw("DATE_TRUNC('month', date) as month, SUM(amount_brl) as total")
                ->groupByRaw("DATE_TRUNC('month', date)")
                ->orderByRaw("DATE_TRUNC('month', date)")
                ->get()
                ->keyBy(fn ($m) => \Carbon\Carbon::parse($m->month)->format('M/y'));
        }

        $allMonths = $monthlyDeposits->keys()->merge($monthlyReturns->keys())->merge($monthlySpends->keys())->unique()->sort();
        $monthly = $allMonths->map(fn ($month) => [
            'month' => $month,
            'deposits' => round((float) ($monthlyDeposits[$month]->deposits ?? 0), 2),
            'returns' => round((float) (($monthlyReturns[$month]->total ?? 0) + ($monthlySpends[$month]->total ?? 0)), 2),
        ])->values();

        // Manual balances set by user
        $manualBalances = $user->globalAccountBalances()
            ->get()
            ->map(fn ($b) => [
                'currency' => $b->currency,
                'balance' => (float) $b->balance,
            ]);

        return response()->json([
            'transactions' => $transactions,
            'totals' => $totals,
            'total_returns' => round($totalReturns + $totalSpends, 2),
            'monthly' => $monthly,
            'manual_balances' => $manualBalances,
        ]);
    }

    public function globalAccountSpend(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'description' => 'nullable|string|max:255',
            'currency' => 'required|in:USD,EUR',
        ]);

        $user = $request->user();

        $category = \App\Models\Category::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('is_default', true);
        })->where('name', 'Conta Global')->first();

        if (!$category) {
            $category = \App\Models\Category::create([
                'name' => 'Conta Global',
                'type' => 'investment',
                'color' => '#475569',
                'icon' => 'globe',
                'user_id' => $user->id,
            ]);
        }

        $transaction = $user->transactions()->create([
            'type' => 'expense',
            'description' => $request->description ?: 'Gasto Conta Global',
            'amount' => $request->amount,
            'currency' => $request->currency,
            'exchange_rate' => null,
            'amount_brl' => $request->amount,
            'date' => $request->date,
            'category_id' => $category->id,
            'notes' => 'Gasto manual - Conta Global (' . $request->currency . ')',
        ]);

        return response()->json([
            'message' => 'Gasto registrado',
            'transaction' => $transaction,
        ]);
    }

    public function globalAccountAdjust(Request $request)
    {
        $request->validate([
            'balances' => 'required|array',
            'balances.*.currency' => 'required|in:USD,EUR',
            'balances.*.balance' => 'required|numeric|min:0',
        ]);

        $user = $request->user();
        $results = [];

        foreach ($request->balances as $entry) {
            $balance = \App\Models\GlobalAccountBalance::updateOrCreate(
                ['user_id' => $user->id, 'currency' => $entry['currency']],
                ['balance' => $entry['balance']],
            );
            $results[] = [
                'currency' => $balance->currency,
                'balance' => (float) $balance->balance,
            ];
        }

        return response()->json([
            'message' => 'Saldo atualizado',
            'balances' => $results,
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $account = $request->user()->investmentAccounts()->findOrFail($id);
        $account->delete();

        return response()->json(['message' => 'Cofrinho removido']);
    }
}
