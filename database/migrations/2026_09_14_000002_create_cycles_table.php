<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // ex: "Cycle 2026", "Cycle Kinshasa 2026-2027"
            $table->integer('year');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['planned', 'active', 'closed'])->default('active');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('set null');
            $table->decimal('montant_cotisation_mensuelle', 15, 2)->default(50.00);
            $table->decimal('total_collected', 15, 2)->default(0);
            $table->integer('total_members')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycles');
    }
};
