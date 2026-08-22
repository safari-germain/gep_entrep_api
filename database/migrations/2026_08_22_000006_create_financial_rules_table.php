<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('value_numeric', 15, 4)->default(0.0000);
            $table->string('value_string')->nullable();
            $table->json('value_json')->nullable();
            $table->enum('type', ['amount', 'percentage', 'multiplier', 'months', 'boolean', 'json'])->default('amount');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_rules');
    }
};
