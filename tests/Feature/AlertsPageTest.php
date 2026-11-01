<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// Alerts: a sidebar item with its own page, listing the person's own
// notifications, with an unread count on the nav item.
class AlertsPageTest extends TestCase
{
    use RefreshDatabase;

    private function alert(User $user, string $message, bool $read = false): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\DocumentOpened',
            'data' => ['message' => $message, 'url' => '/invoices'],
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_the_page_lists_your_own_alerts_and_the_nav_counts_the_unread(): void
    {
        $sam = User::factory()->teamMember()->create(['permissions' => ['invoices']]);
        $this->alert($sam, 'Acme opened invoice #1001');
        $this->alert($sam, 'Acme opened invoice #1000', read: true);
        $this->alert(User::factory()->create(), 'Someone else’s alert');

        $this->actingAs($sam)->get('/alerts')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Alerts/Index')
            ->has('notifications', 2)
            ->where('alertsUnread', 1));
    }

    public function test_marking_an_alert_read_lowers_the_count(): void
    {
        $sam = User::factory()->teamMember()->create(['permissions' => ['invoices']]);
        $this->alert($sam, 'Acme opened invoice #1001');
        $id = $sam->notifications()->value('id');

        $this->actingAs($sam)->postJson("/api/notifications/{$id}/read")->assertOk();

        $this->actingAs($sam)->get('/alerts')->assertInertia(fn (Assert $page) => $page->where('alertsUnread', 0));
    }

    public function test_mark_all_read_clears_only_your_own(): void
    {
        $sam = User::factory()->teamMember()->create(['permissions' => ['invoices']]);
        $other = User::factory()->create();
        $this->alert($sam, 'Acme opened invoice #1001');
        $this->alert($sam, 'Acme opened invoice #1002');
        $this->alert($other, 'Someone else’s alert');

        $this->actingAs($sam)->postJson('/api/notifications/read-all')->assertNoContent();

        $this->assertSame(0, $sam->unreadNotifications()->count());
        $this->assertSame(2, $sam->notifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_alerts_go_with_the_invoices_permission(): void
    {
        $alex = User::factory()->teamMember()->create(['permissions' => ['proposals']]);

        $this->actingAs($alex)->get('/alerts')->assertForbidden();
        $this->actingAs($alex)->get('/proposals')->assertInertia(fn (Assert $page) => $page->where('alertsUnread', null));
    }

    public function test_the_accountant_has_alerts_too(): void
    {
        $accountant = User::factory()->create(['role' => User::ROLE_ACCOUNTANT]);

        $this->actingAs($accountant)->get('/alerts')->assertOk();
    }
}
