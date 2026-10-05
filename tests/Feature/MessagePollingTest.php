<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagePollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_version_changes_with_new_replies_reactions_and_deletions_only(): void
    {
        $admin = User::factory()->create();
        $project = Company::create(['name' => 'Alder & Finch Design'])->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);
        $thread = Message::create(['project_id' => $project->id, 'subject' => 'Kickoff', 'body' => 'Hi', 'sender_user_id' => $admin->id, 'sent_at' => now()]);
        $url = "/api/projects/{$project->id}/messages/version";
        $version = fn () => $this->actingAs($admin)->getJson($url)->assertOk()->json('version');

        $start = $version();
        $this->assertSame($start, $version(), 'Nothing changed, same version.');

        $this->travel(1)->minute();
        $reply = Message::create(['project_id' => $project->id, 'parent_id' => $thread->id, 'body' => 'Sounds good', 'sender_user_id' => $admin->id, 'sent_at' => now()]);
        $afterReply = $version();
        $this->assertNotSame($start, $afterReply);

        $this->travel(1)->minute();
        $reply->reactions()->create(['user_id' => $admin->id, 'emoji' => '👍']);
        $afterReaction = $version();
        $this->assertNotSame($afterReply, $afterReaction);

        $this->travel(1)->minute();
        $reply->delete();
        $this->assertNotSame($afterReaction, $version());
    }

    public function test_the_page_starts_from_the_current_version(): void
    {
        $admin = User::factory()->create();
        $project = Company::create(['name' => 'Alder & Finch Design'])->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);

        $pageVersion = $this->actingAs($admin)->get("/projects/{$project->id}")->viewData('page')['props']['messagesVersion'];

        $this->assertSame($pageVersion, $this->actingAs($admin)->getJson("/api/projects/{$project->id}/messages/version")->json('version'));
    }

    public function test_only_people_who_can_see_the_project_can_check_it(): void
    {
        $project = Company::create(['name' => 'Alder & Finch Design'])->projects()->create(['name' => 'Brand refresh', 'status' => 'active']);

        $this->actingAs(User::factory()->teamMember()->create())->getJson("/api/projects/{$project->id}/messages/version")->assertForbidden();
    }
}
