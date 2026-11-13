<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ohada_accounts')) {
            Schema::create('ohada_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('account_number', 20)->unique();
                $table->string('label', 150);
                $table->enum('category', ['actif', 'passif', 'charge', 'produit', 'hors_bilan']);
                $table->unsignedTinyInteger('ohada_class'); // 1 à 7
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->boolean('is_reconcilable')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('parent_id')->references('id')->on('ohada_accounts')->onDelete('set null');
                $table->index(['category', 'ohada_class']);
            });
        }

        if (!Schema::hasTable('accounting_entries')) {
            Schema::create('accounting_entries', function (Blueprint $table) {
                $table->id();
                $table->string('entry_number', 50)->unique();
                $table->date('entry_date');
                $table->string('period', 7)->index(); // YYYY-MM
                $table->string('source_type', 100)->nullable(); // credit, deposit, cycle, overdraft, etc.
                $table->unsignedBigInteger('source_id')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('label', 255);
                $table->decimal('total_debit', 15, 2)->default(0.00);
                $table->decimal('total_credit', 15, 2)->default(0.00);
                $table->enum('status', ['draft', 'posted', 'reversed'])->default('posted');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('branch_id')->references('id')->on('branches')->onDelete('set null');
                $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
                $table->index(['entry_date', 'status']);
            });
        }

        if (!Schema::hasTable('accounting_entry_lines')) {
            Schema::create('accounting_entry_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('accounting_entry_id');
                $table->unsignedBigInteger('ohada_account_id');
                $table->enum('direction', ['debit', 'credit']);
                $table->decimal('amount', 15, 2);
                $table->string('auxiliary_type', 50)->nullable(); // user, organisation, credit
                $table->unsignedBigInteger('auxiliary_id')->nullable();
                $table->string('narration', 255)->nullable();
                $table->timestamps();

                $table->foreign('accounting_entry_id')->references('id')->on('accounting_entries')->onDelete('cascade');
                $table->foreign('ohada_account_id')->references('id')->on('ohada_accounts')->onDelete('restrict');
                $table->index(['direction', 'auxiliary_type', 'auxiliary_id'], 'idx_entry_lines_aux');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_entry_lines');
        Schema::dropIfExists('accounting_entries');
        Schema::dropIfExists('ohada_accounts');
    }
};
