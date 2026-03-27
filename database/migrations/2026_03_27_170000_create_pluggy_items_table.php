<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pluggy_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pluggy_item_id')->unique();
            $table->string('connector_name');
            $table->string('connector_logo')->nullable();
            $table->string('status')->default('UPDATED');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('pluggy_transaction_id')->nullable()->unique()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('pluggy_transaction_id');
        });

        Schema::dropIfExists('pluggy_items');
    }
};
