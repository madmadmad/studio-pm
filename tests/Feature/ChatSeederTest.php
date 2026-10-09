<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\ChatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// #general, with every active staff member -- and only them.
class ChatSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_puts_every_active_staff_member_in_general_and_can_run_again(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->teamMember()->create();
        $gone = User::factory()->teamMember()->create(['deactivated_at' => now()]);

        $this->seed(ChatSeeder::class);

        $general = Conversation::where('slug', 'general')->sole();
        $this->assertSame('channel', $general->type);
        $this->assertSame($admin->id, $general->created_by);
        $this->assertEqualsCanonicalizing([$admin->id, $member->id], $general->members->pluck('id')->all());

        $newcomer = User::factory()->teamMember()->create();
        $this->seed(ChatSeeder::class);

        $this->assertSame(1, Conversation::where('slug', 'general')->count());
        $this->assertEqualsCanonicalizing([$admin->id, $member->id, $newcomer->id], $general->fresh()->members->pluck('id')->all());
        $this->assertFalse($general->fresh()->hasMember($gone));
    }
}
