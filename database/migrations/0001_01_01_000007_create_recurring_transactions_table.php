<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['income', 'expense', 'investment']);
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('BRL');
            $table->enum('frequency', ['weekly', 'biweekly', 'monthly', 'yearly']);
            $table->date('next_due_date');
            $table->date('last_generated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_transactions');
    }
};
