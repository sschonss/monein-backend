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
        $period = $request->input('period', 'month'); // week, month, year, all

        $now = Carbon::now();

        switch ($period) {
            case 'week':
                $start = $now->copy()->startOfWeek();
                $end = $now->copy()->endOfWeek();
                break;
            case 'year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                break;
            case 'all':
                $start = null;
                $end = null;
                break;
            default: // month
                if ($request->filled('month')) {
                    $date = Carbon::createFromFormat('Y-m', $request->month);
                    $start = $date->copy()->startOfMonth();
                    $end = $date->copy()->endOfMonth();
                } else {
                    $start = $now->copy()->startOfMonth();
                    $end = $now->copy()->endOfMonth();
                }
                break;
        }

        $query = $user->transactions();
        if ($start && $end) {
            $query->whereBetween('date', [$start->toDateString(), $end->toDateString()]);
        }

        $totals = $query->selectRaw("
            COALESCE(SUM(CASE WHEN type = 'income' THEN amount_brl ELSE 0 END), 0) as total_income,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount_brl ELSE 0 END), 0) as total_expense,
            COALESCE(SUM(CASE WHEN type = 'investment' THEN amount_brl ELSE 0 END), 0) as total_investment
        ")->first();

        $totalIncome = (float) $totals->total_income;
        $totalExpense = (float) $totals->total_expense;
        $totalInvestment = (float) $totals->total_investment;

        $catQuery = $user->transactions()
            ->where('transactions.type', 'expense')
            ->join('categories', 'transactions.category_id', '=', 'categories.id')
            ->selectRaw('categories.name, SUM(transactions.amount_brl) as total, categories.color')
            ->groupBy('categories.id', 'categories.name', 'categories.color');

        if ($start && $end) {
            $catQuery->whereBetween('transactions.date', [$start->toDateString(), $end->toDateString()]);
        }

        $byCategory = $catQuery->get();

        // Monthly evolution - last 6 months (or 12 for year/all)
        $evolutionMonths = ($period === 'year' || $period === 'all') ? 12 : 6;
        $monthlyEvolution = [];
        for ($i = $evolutionMonths - 1; $i >= 0; $i--) {
            $monthDate = $now->copy()->subMonths($i);
            $monthStart = $monthDate->copy()->startOfMonth();
            $monthEnd = $monthDate->copy()->endOfMonth();

            $monthTotals = $user->transactions()
                ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN type = 'income' THEN amount_brl ELSE 0 END), 0) as income,
                    COALESCE(SUM(CASE WHEN type = 'expense' THEN amount_brl ELSE 0 END), 0) as expense,
                    COALESCE(SUM(CASE WHEN type = 'investment' THEN amount_brl ELSE 0 END), 0) as investment
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
            'by_category' => $byCategory,
            'monthly_evolution' => $monthlyEvolution,
            'period' => $period,
        ]);
    }
}
