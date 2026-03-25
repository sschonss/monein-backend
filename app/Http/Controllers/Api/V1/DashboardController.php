<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $period = $request->input('period', 'month');

        $now = Carbon::now();

        switch ($period) {
            case 'week':
                $startDate = $now->copy()->startOfWeek()->toDateString();
                $endDate = $now->copy()->endOfWeek()->toDateString();
                break;
            case 'year':
                $startDate = $now->copy()->startOfYear()->toDateString();
                $endDate = $now->copy()->endOfYear()->toDateString();
                break;
            case 'all':
                $startDate = null;
                $endDate = null;
                break;
            default: // month
                if ($request->filled('month')) {
                    $date = Carbon::createFromFormat('Y-m', $request->month);
                    $startDate = $date->copy()->startOfMonth()->toDateString();
                    $endDate = $date->copy()->endOfMonth()->toDateString();
                } else {
                    $startDate = $now->copy()->startOfMonth()->toDateString();
                    $endDate = $now->copy()->endOfMonth()->toDateString();
                }
                break;
        }

        // Fresh query each time to avoid builder mutation
        $baseQuery = function () use ($user, $startDate, $endDate) {
            $q = $user->transactions();
            if ($startDate && $endDate) {
                $q->whereBetween('transactions.date', [$startDate, $endDate]);
            }
            return $q;
        };

        $totals = $baseQuery()
            ->selectRaw("
                COALESCE(SUM(CASE WHEN transactions.type = 'income' THEN transactions.amount_brl ELSE 0 END), 0) as total_income,
                COALESCE(SUM(CASE WHEN transactions.type = 'expense' THEN transactions.amount_brl ELSE 0 END), 0) as total_expense,
                COALESCE(SUM(CASE WHEN transactions.type = 'investment' THEN transactions.amount_brl ELSE 0 END), 0) as total_investment
            ")->first();

        $totalIncome = (float) $totals->total_income;
        $totalExpense = (float) $totals->total_expense;
        $totalInvestment = (float) $totals->total_investment;

        $categoryByType = function (string $type) use ($baseQuery) {
            return $baseQuery()
                ->where('transactions.type', $type)
                ->whereNotNull('transactions.category_id')
                ->join('categories', 'transactions.category_id', '=', 'categories.id')
                ->selectRaw('categories.name, SUM(transactions.amount_brl) as total, categories.color')
                ->groupBy('categories.id', 'categories.name', 'categories.color')
                ->orderByDesc('total')
                ->get();
        };

        $byCategoryExpense = $categoryByType('expense');
        $byCategoryIncome = $categoryByType('income');
        $byCategoryInvestment = $categoryByType('investment');

        // Monthly evolution
        $evolutionMonths = ($period === 'year' || $period === 'all') ? 12 : 6;
        $monthlyEvolution = [];
        for ($i = $evolutionMonths - 1; $i >= 0; $i--) {
            $monthDate = $now->copy()->subMonths($i);
            $ms = $monthDate->copy()->startOfMonth()->toDateString();
            $me = $monthDate->copy()->endOfMonth()->toDateString();

            $monthTotals = $user->transactions()
                ->whereBetween('transactions.date', [$ms, $me])
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN transactions.type = 'income' THEN transactions.amount_brl ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN transactions.type = 'expense' THEN transactions.amount_brl ELSE 0 END), 0) as expense,
                    COALESCE(SUM(CASE WHEN transactions.type = 'investment' THEN transactions.amount_brl ELSE 0 END), 0) as investment
                ")
                ->first();

            $monthlyEvolution[] = [
                'month' => $monthDate->format('M'),
                'income' => (float) $monthTotals->income,
                'expense' => (float) $monthTotals->expense,
                'investment' => (float) $monthTotals->investment,
            ];
        }

        return response()->json([
            'balance' => $totalIncome - $totalExpense,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'total_investment' => $totalInvestment,
            'by_category' => $byCategoryExpense,
            'by_category_income' => $byCategoryIncome,
            'by_category_investment' => $byCategoryInvestment,
            'monthly_evolution' => $monthlyEvolution,
            'period' => $period,
        ]);
    }
}
