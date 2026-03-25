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

        // Ensure categories exist
        $categoryMap = [];
        $categoryNames = collect($parsed)->pluck('category_name')->unique()->toArray();
        $existingCategories = Category::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('is_default', true);
        })->whereIn('name', $categoryNames)->get()->keyBy('name');

        foreach (collect($parsed)->unique('category_name') as $tx) {
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

        // Import transactions, checking for duplicates
        $imported = 0;
        $skipped = 0;

        foreach ($parsed as $tx) {
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

            $user->transactions()->create([
                'type' => $tx['type'],
                'description' => $tx['description'],
                'amount' => $tx['amount'],
                'currency' => 'BRL',
                'exchange_rate' => null,
                'amount_brl' => $tx['amount'],
                'date' => $tx['date'],
                'category_id' => $categoryMap[$tx['category_name']] ?? null,
                'notes' => 'Importado do extrato PicPay - ' . $tx['time'],
            ]);

            $imported++;
        }

        return response()->json([
            'message' => 'Importação concluída',
            'imported' => $imported,
            'skipped' => $skipped,
            'total' => count($parsed),
        ]);
    }
}
