<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The double-entry ledger under the app's expenses, payments and income
// (docs/ledger-plan.md). Money here is integer cents, never decimal.
// Entries and their lines are only ever written by App\Services\Ledger and
// never edited or deleted -- a correction is a reversing entry -- so the
// foreign keys into them restrict deletes instead of cascading.
return new class extends Migration
{
    public function up(): void
    {
        // The chart of accounts. Never deleted, only deactivated.
        // system_key is the code's handle on an account ('checking',
        // 'capital_one_card'...), so codes and names can change freely.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type'); // asset, liability, equity, income, expense
            $table->string('system_key')->nullable()->unique();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // A closed stretch of the books: no entry may be dated inside a
        // locked period.
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Schema only for now -- the reconciliation screen is out of scope.
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('statement_date');
            $table->bigInteger('statement_ending_balance_cents');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('entry_number')->unique();
            $table->date('entry_date')->index();
            $table->string('memo')->nullable();
            // The record that caused it (an Expense, a Payment...); null for
            // entries made by hand.
            $table->nullableMorphs('source');
            // Unique: an entry is reversed at most once.
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamp('posted_at');
            // Null for entries posted without a user (webhooks, imports), or
            // once that user is deleted.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $this->amountColumns($table);
            $table->string('description')->nullable();
            // The client a line belongs to. Restricted, so a client with
            // ledger history can't be deleted out from under it.
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('bank_reconciliation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('alter table journal_lines add constraint journal_lines_one_side check ('.$this->oneSide().')');
        }
    }

    // Each line is exactly one of a debit or a credit, greater than zero --
    // enforced by the database, not just the Ledger. SQLite can't add a
    // CHECK to an existing table, so there it rides on the column
    // definition. CAUTION: Laravel rebuilds a SQLite table to alter it, and
    // the rebuild drops this CHECK; any later migration that alters
    // journal_lines must recreate it. LedgerTest checks it's still there.
    private function amountColumns(Blueprint $table): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $table->rawColumn('debit_cents', 'integer not null default 0');
            $table->rawColumn('credit_cents', 'integer not null default 0 check ('.$this->oneSide().')');

            return;
        }

        $table->unsignedBigInteger('debit_cents')->default(0);
        $table->unsignedBigInteger('credit_cents')->default(0);
    }

    private function oneSide(): string
    {
        return '(debit_cents > 0 and credit_cents = 0) or (credit_cents > 0 and debit_cents = 0)';
    }

    // Without foreign key checks: the restricts above (an account's
    // heading, a reversal's original) would otherwise refuse to drop
    // tables that have rows.
    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            Schema::dropIfExists('journal_lines');
            Schema::dropIfExists('journal_entries');
            Schema::dropIfExists('bank_reconciliations');
            Schema::dropIfExists('accounting_periods');
            Schema::dropIfExists('accounts');
        });
    }
};
