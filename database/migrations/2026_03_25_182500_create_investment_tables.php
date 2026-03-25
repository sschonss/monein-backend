<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('source')->default('picpay_cofrinho');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index('user_id');
        });

        Schema::create('investment_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('investment_accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['deposit', 'withdrawal', 'yield']);
            $table->decimal('amount', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->date('date');
            $table->timestamps();

            $table->index(['account_id', 'date']);
            $table->index('user_id');
            $table->unique(['account_id', 'date', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_movements');
        Schema::dropIfExists('investment_accounts');
    }
};
