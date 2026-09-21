<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Expense is now the single source of truth for expense-side
    // bookkeeping; Transaction keeps handling income only. Old expense rows
    // carried no billable/category-id concept, so they land here unbilled
    // and uncategorized, with their free-text category preserved as
    // source_label so nothing is lost.
    public function up(): void
    {
        $expenseTransactions = DB::table('transactions')->where('type', 'expense')->get();

        foreach ($expenseTransactions as $transaction) {
            DB::table('expenses')->insert([
                'name' => $transaction->description ?: ($transaction->category ?: 'Expense'),
                'amount' => $transaction->amount,
                'currency' => 'USD',
                'project_id' => $transaction->project_id,
                'is_billable' => false,
                'markup_percent' => 0,
                'date' => $transaction->occurred_on,
                'is_recurring' => false,
                'source_label' => $transaction->category,
                'billing_status' => 'unbilled',
                'created_at' => $transaction->created_at,
                'updated_at' => $transaction->updated_at,
            ]);
        }

        DB::table('transactions')->where('type', 'expense')->delete();
    }

    // Not reversible: the split of a Transaction's free-text category into
    // Expense's structured fields loses information a straight rollback
    // can't reconstruct.
    public function down(): void {}
};
