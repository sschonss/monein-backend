<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RecurringTransactionController extends Controller
{
    public function index(Request $request)
    {
        $recurring = $request->user()->recurringTransactions()
            ->with('category')
            ->orderBy('next_due_date', 'asc')
            ->get();

        return response()->json($recurring);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:income,expense,investment',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|max:3',
            'frequency' => 'required|in:weekly,biweekly,monthly,yearly',
            'next_due_date' => 'required|date',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        $validated['currency'] = $validated['currency'] ?? 'BRL';

        $recurring = $request->user()->recurringTransactions()->create($validated);

        $recurring->load('category');

        return response()->json($recurring, 201);
    }

    public function show(Request $request, $id)
    {
        $recurring = $request->user()->recurringTransactions()
            ->with('category')
            ->findOrFail($id);

        return response()->json($recurring);
    }

    public function update(Request $request, $id)
    {
        $recurring = $request->user()->recurringTransactions()->findOrFail($id);

        $validated = $request->validate([
            'type' => 'required|in:income,expense,investment',
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|max:3',
            'frequency' => 'required|in:weekly,biweekly,monthly,yearly',
            'next_due_date' => 'required|date',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        $validated['currency'] = $validated['currency'] ?? 'BRL';

        $recurring->update($validated);

        $recurring->load('category');

        return response()->json($recurring);
    }

    public function destroy(Request $request, $id)
    {
        $recurring = $request->user()->recurringTransactions()->findOrFail($id);

        $recurring->delete();

        return response()->json(['message' => 'Recurring transaction deleted']);
    }

    public function toggle(Request $request, $id)
    {
        $recurring = $request->user()->recurringTransactions()->findOrFail($id);

        $recurring->update(['is_active' => !$recurring->is_active]);

        return response()->json($recurring);
    }
}
