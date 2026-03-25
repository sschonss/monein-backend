<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $this->createDefaultCategories($user);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    private function createDefaultCategories(User $user): void
    {
        $defaults = [
            // Income
            ['name' => 'Salário', 'type' => 'income', 'icon' => 'briefcase', 'color' => '#22c55e'],
            ['name' => 'Freelance', 'type' => 'income', 'icon' => 'laptop', 'color' => '#10b981'],
            ['name' => 'Outros Rendimentos', 'type' => 'income', 'icon' => 'plus-circle', 'color' => '#34d399'],
            // Expenses
            ['name' => 'Alimentação', 'type' => 'expense', 'icon' => 'utensils', 'color' => '#ef4444'],
            ['name' => 'Transporte', 'type' => 'expense', 'icon' => 'car', 'color' => '#f97316'],
            ['name' => 'Moradia', 'type' => 'expense', 'icon' => 'home', 'color' => '#eab308'],
            ['name' => 'Saúde', 'type' => 'expense', 'icon' => 'heart-pulse', 'color' => '#ec4899'],
            ['name' => 'Educação', 'type' => 'expense', 'icon' => 'graduation-cap', 'color' => '#8b5cf6'],
            ['name' => 'Lazer', 'type' => 'expense', 'icon' => 'gamepad-2', 'color' => '#06b6d4'],
            ['name' => 'Assinaturas', 'type' => 'expense', 'icon' => 'repeat', 'color' => '#f59e0b'],
            ['name' => 'Outros Gastos', 'type' => 'expense', 'icon' => 'receipt', 'color' => '#6b7280'],
            // Investment
            ['name' => 'Renda Fixa', 'type' => 'investment', 'icon' => 'landmark', 'color' => '#3b82f6'],
            ['name' => 'Ações', 'type' => 'investment', 'icon' => 'trending-up', 'color' => '#6366f1'],
            ['name' => 'Cripto', 'type' => 'investment', 'icon' => 'bitcoin', 'color' => '#f59e0b'],
            ['name' => 'Fundos', 'type' => 'investment', 'icon' => 'pie-chart', 'color' => '#14b8a6'],
        ];

        foreach ($defaults as $cat) {
            $user->categories()->create($cat);
        }
    }
}
