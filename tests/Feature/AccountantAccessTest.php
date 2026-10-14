<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Expense;
use App\Models\User;
use App\Notifications\AccountantSignInLink;
use App\Notifications\StaffInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// The studio's accountant: invited from Team, signs in only by an emailed
// link, and sees Bookkeeping plus invoices and expenses to look at --
// changing nothing but journal entries. Everything else is closed to them,
// on the server, whatever the page.
class AccountantAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    private function accountant(array $extra = []): User
    {
        return User::factory()->create(['name' => 'Casey Ledger', 'email' => 'casey@cpa.example', 'role' => User::ROLE_ACCOUNTANT, ...$extra]);
    }

    // The link from the most recent email to this person.
    private function linkFor(User $user): string
    {
        $url = null;
        Notification::assertSentTo($user, AccountantSignInLink::class, function (AccountantSignInLink $n) use ($user, &$url) {
            $url = $n->toMail($user)->actionUrl;

            return true;
        });

        return $url;
    }

    public function test_inviting_the_accountant_emails_a_sign_in_link_not_a_password_invite(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->postJson('/api/users', ['name' => 'Casey Ledger', 'email' => 'casey@cpa.example', 'role' => 'accountant', 'permissions' => ['clients']])
            ->assertCreated();

        $casey = User::where('email', 'casey@cpa.example')->sole();
        $this->assertTrue($casey->isAccountant());
        $this->assertNull($casey->permissions);
        $this->assertNull($casey->invite_token);
        Notification::assertSentTo($casey, AccountantSignInLink::class);
        Notification::assertNotSentTo($casey, StaffInvitation::class);
        $this->assertTrue($casey->login_token_expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
    }

    public function test_the_link_signs_in_once_and_lands_on_bookkeeping(): void
    {
        Notification::fake();
        $casey = $this->accountant();
        $this->actingAs($this->admin)->postJson("/api/users/{$casey->id}/resend-invite")->assertOk();
        $link = $this->linkFor($casey);
        auth()->forgetGuards();
        $this->app['session']->flush();

        $this->get($link)->assertRedirect('/bookkeeping');
        $this->assertAuthenticatedAs($casey, 'web');

        auth()->forgetGuards();
        $this->app['session']->flush();
        $this->get($link)->assertRedirect('/login');
        $this->assertGuest('web');
    }

    public function test_an_expired_or_altered_link_does_not_sign_in(): void
    {
        Notification::fake();
        $casey = $this->accountant();
        $this->post('/login/link', ['email' => 'casey@cpa.example'])->assertSessionHas('status');
        $link = $this->linkFor($casey);

        $this->get($link.'x')->assertForbidden();

        $this->travel(21)->minutes();
        $this->get($link)->assertForbidden(); // the signature has expired too
        $this->assertGuest('web');
    }

    public function test_asking_for_a_link_says_the_same_whoever_asks(): void
    {
        Notification::fake();
        $casey = $this->accountant();
        $staff = User::factory()->teamMember()->create(['email' => 'sam@studio.example']);
        $gone = $this->accountant(['email' => 'old@cpa.example', 'deactivated_at' => now()]);

        foreach (['casey@cpa.example', 'sam@studio.example', 'nobody@example.com', 'old@cpa.example'] as $email) {
            $this->post('/login/link', ['email' => $email])->assertSessionHas('status', "If that's the accountant's email, a sign-in link is on its way. It works for 20 minutes.");
            $this->app['cache']->flush(); // past the one-a-minute limit
        }

        Notification::assertSentTo($casey, AccountantSignInLink::class);
        Notification::assertNotSentTo([$staff, $gone], AccountantSignInLink::class);
    }

    public function test_the_accountant_cannot_sign_in_with_a_password(): void
    {
        $this->accountant(['password' => 'a-known-password']);
        $staff = User::factory()->teamMember()->withoutTwoFactor()->create(['email' => 'sam@studio.example', 'password' => 'a-known-password']);

        $this->post('/login', ['email' => 'casey@cpa.example', 'password' => 'a-known-password']);
        $this->assertGuest('web');

        $this->post('/login', ['email' => 'sam@studio.example', 'password' => 'a-known-password']);
        $this->assertAuthenticatedAs($staff, 'web');
    }

    public function test_bookkeeping_invoices_and_expenses_are_open_to_look_at(): void
    {
        $casey = $this->accountant();
        $company = Company::create(['name' => 'Acme']);
        $invoice = $company->invoices()->create(['status' => 'sent', 'issued_on' => '2026-10-01', 'due_on' => '2026-10-31']);

        foreach (['/bookkeeping', '/bookkeeping/journal', '/bookkeeping/accounts', '/bookkeeping/ledger/trial-balance', '/bookkeeping/profit-loss.csv', '/invoices', "/invoices/{$invoice->id}", '/expenses'] as $url) {
            $this->actingAs($casey, 'web')->get($url)->assertOk();
        }
        $this->actingAs($casey)->getJson("/api/invoices/{$invoice->id}")->assertOk();
    }

    public function test_everything_else_is_closed(): void
    {
        $casey = $this->accountant();
        $company = Company::create(['name' => 'Acme']);
        $project = $company->projects()->create(['name' => 'Rebrand']);

        foreach (['/', '/my-work', '/overview', '/projects', "/projects/{$project->id}", '/clients', '/proposals', '/chat', '/users', '/settings', '/profile', '/services', '/time-entries'] as $url) {
            $this->actingAs($casey, 'web')->get($url)->assertRedirect('/bookkeeping');
        }
        foreach ([['get', '/api/chat/unread'], ['get', "/api/projects/{$project->id}/messages"], ['post', "/api/companies/{$company->id}/projects"], ['get', '/api/users']] as [$method, $url]) {
            $this->actingAs($casey)->json($method, $url)->assertForbidden();
        }
    }

    public function test_invoices_and_expenses_are_read_only(): void
    {
        $casey = $this->accountant();
        $company = Company::create(['name' => 'Acme']);
        $invoice = $company->invoices()->create(['status' => 'sent', 'issued_on' => '2026-10-01', 'due_on' => '2026-10-31']);
        $expense = Expense::create(['name' => 'Figma', 'amount' => 20, 'currency' => 'USD', 'date' => '2026-10-01', 'is_billable' => false, 'billing_status' => 'unbilled']);

        $attempts = [
            ['post', "/api/companies/{$company->id}/invoices", ['items' => []]],
            ['patch', "/api/invoices/{$invoice->id}", ['status' => 'paid']],
            ['post', "/api/invoices/{$invoice->id}/mark-paid", ['method' => 'check']],
            ['post', "/api/invoices/{$invoice->id}/send", []],
            ['delete', "/api/invoices/{$invoice->id}", []],
            ['post', '/api/expenses', ['name' => 'x']],
            ['delete', "/api/expenses/{$expense->id}", []],
            ['post', '/api/accounts', ['name' => 'New']],
            ['post', '/api/journal-entries/transfer', []],
            ['post', '/api/accounting-periods', []],
        ];
        foreach ($attempts as [$method, $url, $body]) {
            $this->actingAs($casey)->json($method, $url, $body)->assertForbidden();
        }
        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertNotNull($expense->fresh());
    }

    public function test_the_accountant_can_post_and_reverse_a_journal_entry(): void
    {
        $casey = $this->accountant();

        $id = $this->actingAs($casey)->postJson('/api/journal-entries', [
            'entry_date' => '2026-06-30',
            'memo' => 'Depreciation',
            'lines' => [
                ['account_id' => Account::forKey('checking')->id, 'debit' => '100'],
                ['account_id' => Account::forKey('other_income')->id, 'credit' => '100'],
            ],
        ])->assertOk()->json('id');

        $this->actingAs($casey)->postJson("/api/journal-entries/{$id}/reverse")->assertOk();
    }

    public function test_the_accountant_is_not_staff(): void
    {
        $casey = $this->accountant();
        $channel = Conversation::create(['type' => 'channel', 'name' => 'general', 'slug' => 'general']);

        // Not in Chat, nor in any list of people to pick.
        $this->actingAs($this->admin, 'web')->get('/chat')->assertInertia(fn ($page) => $page->where('staff', fn ($staff) => collect($staff)->pluck('id')->doesntContain($casey->id)));
        $this->actingAs($this->admin)->postJson('/api/chat/direct', ['user_ids' => [$casey->id]])->assertStatus(422);
        $project = Company::create(['name' => 'Acme'])->projects()->create(['name' => 'Rebrand']);
        $this->actingAs($this->admin)->postJson("/api/projects/{$project->id}/assignments", ['user_id' => $casey->id])->assertStatus(422);
        $this->assertFalse($casey->can('view', $channel));
        $this->assertFalse(User::staff()->whereKey($casey->id)->exists());
        $this->assertFalse(User::withPermission('invoices')->contains($casey));
        $this->assertTrue($casey->hasPermission('bookkeeping'));
        $this->assertFalse($casey->hasPermission('settings'));
        $this->assertFalse($casey->hasPermission('clients'));
    }
}
