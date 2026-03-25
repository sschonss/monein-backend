<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($request->filled('month')) {
            $date = Carbon::createFromFormat('Y-m', $request->month);
        } else {
            $date = Carbon::now();
        }

        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        $totals = $user->transactions()
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount_brl ELSE 0 END), 0) as total_income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount_brl ELSE 0 END), 0) as total_expense,
                COALESCE(SUM(CASE WHEN type = 'investment' THEN amount_brl ELSE 0 END), 0) as total_investment
            ")
            ->first();

        $totalIncome = (float) $totals->total_income;
        $totalExpense = (float) $totals->total_expense;
        $totalInvestment = (float) $totals->total_investment;

        // Expenses grouped by category
        $byCategory = $user->transactions()
            ->where('type', 'expense')
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->join('categories', 'transactions.category_id', '=', 'categories.id')
            ->selectRaw('categories.name, SUM(transactions.amount_brl) as total, categories.color')
            ->groupBy('categories.id', 'categories.name', 'categories.color')
            ->get();

        // Monthly evolution - last 6 months
        $monthlyEvolution = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthDate = $date->copy()->subMonths($i);
            $monthStart = $monthDate->copy()->startOfMonth();
            $monthEnd = $monthDate->copy()->endOfMonth();

            $monthTotals = $user->transactions()
                ->whereBetween('date', [$monthStart, $monthEnd])
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
        ]);
    }
}
