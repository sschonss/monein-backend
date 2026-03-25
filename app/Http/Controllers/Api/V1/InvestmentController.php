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

        return response()->json([
            'total_balance' => round($totalBalance, 2),
            'total_deposited' => round($totalDeposited, 2),
            'total_withdrawn' => round($totalWithdrawn, 2),
            'total_yield' => round($totalYield, 2),
            'accounts_count' => $accounts->count(),
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

    public function destroy(Request $request, int $id)
    {
        $account = $request->user()->investmentAccounts()->findOrFail($id);
        $account->delete();

        return response()->json(['message' => 'Cofrinho removido']);
    }
}
