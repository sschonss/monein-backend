<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request)
    {
        $tags = $request->user()->tags()->get();

        return response()->json($tags);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7',
        ]);

        $tag = $request->user()->tags()->create($validated);

        return response()->json($tag, 201);
    }

    public function update(Request $request, $id)
    {
        $tag = $request->user()->tags()->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:7',
        ]);

        $tag->update($validated);

        return response()->json($tag);
    }

    public function destroy(Request $request, $id)
    {
        $tag = $request->user()->tags()->findOrFail($id);

        $tag->delete();

        return response()->json(['message' => 'Tag deleted']);
    }
}
