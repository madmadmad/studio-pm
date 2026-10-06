<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function expenseWithReceipt(User $user): Expense
    {
        $id = $this->actingAs($user)->post('/api/expenses', [
            'name' => 'Stock photography',
            'amount' => 50,
            'date' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 40, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');

        return Expense::find($id);
    }

    public function test_a_receipt_is_stored_privately_and_served_to_someone_with_the_expenses_permission(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $manager = User::factory()->create();

        $expense = $this->expenseWithReceipt($manager);

        Storage::disk('local')->assertExists($expense->receipt_path);
        Storage::disk('public')->assertMissing($expense->receipt_path);
        $this->assertStringNotContainsString('/storage/', $expense->receipt_url);

        $response = $this->actingAs($manager)->get($expense->receipt_url)->assertOk();
        $this->assertSame(Storage::disk('local')->get($expense->receipt_path), $response->streamedContent());
    }

    public function test_someone_without_the_expenses_permission_cannot_see_a_receipt(): void
    {
        Storage::fake('local');
        $expense = $this->expenseWithReceipt(User::factory()->create());

        $this->actingAs(User::factory()->teamMember()->create())->get($expense->receipt_url)->assertForbidden();
    }

    public function test_a_guest_cannot_see_a_receipt(): void
    {
        Storage::fake('local');
        $expense = $this->expenseWithReceipt(User::factory()->create());
        $this->app['auth']->forgetGuards();

        $this->getJson($expense->receipt_url)->assertUnauthorized();
    }

    public function test_replacing_a_receipt_deletes_the_old_file_and_changes_the_url(): void
    {
        Storage::fake('local');
        $manager = User::factory()->create();
        $expense = $this->expenseWithReceipt($manager);
        [$oldPath, $oldUrl] = [$expense->receipt_path, $expense->receipt_url];

        $this->actingAs($manager)->post("/api/expenses/{$expense->id}", [
            '_method' => 'PUT',
            'receipt' => UploadedFile::fake()->create('new.pdf', 40, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        $expense->refresh();
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($expense->receipt_path);
        $this->assertNotSame($oldUrl, $expense->receipt_url);
    }

    public function test_the_migration_moves_existing_receipts_off_the_public_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('public')->put('expense-receipts/old.pdf', 'old-receipt');
        Expense::create(['name' => 'Fonts', 'amount' => 20, 'date' => now(), 'receipt_path' => 'expense-receipts/old.pdf']);

        (require database_path('migrations/2026_10_05_150000_move_expense_receipts_to_the_private_disk.php'))->up();

        Storage::disk('public')->assertMissing('expense-receipts/old.pdf');
        $this->assertSame('old-receipt', Storage::disk('local')->get('expense-receipts/old.pdf'));
    }
}
