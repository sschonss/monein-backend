<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'date']);
            $table->index(['user_id', 'type', 'date']);
            $table->index(['user_id', 'category_id']);
            $table->index(['user_id', 'description']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->index(['name', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'date']);
            $table->dropIndex(['user_id', 'type', 'date']);
            $table->dropIndex(['user_id', 'category_id']);
            $table->dropIndex(['user_id', 'description']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['name', 'type']);
        });
    }
};
