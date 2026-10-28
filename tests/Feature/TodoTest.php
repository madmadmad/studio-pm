<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_running_list_added_to_ticked_and_cleared(): void
    {
        $me = User::factory()->teamMember()->create();

        foreach (['Call the printer', 'Renew domain', 'Order toner'] as $title) {
            $this->actingAs($me)->postJson('/api/todos', ['title' => "  {$title}  "])->assertCreated();
        }
        $ids = Todo::orderBy('id')->pluck('id');

        // Ticked: off the open list, onto Done.
        $this->actingAs($me)->patchJson("/api/todos/{$ids[1]}", ['done' => true])->assertOk();
        $this->actingAs($me)->patchJson("/api/todos/{$ids[0]}", ['title' => 'Call the printer re: proofs'])->assertOk();

        $list = $this->actingAs($me)->getJson('/api/todos')->assertOk();
        $this->assertEquals(['Call the printer re: proofs', 'Order toner'], collect($list->json('open'))->pluck('title')->all());
        $this->assertEquals(['Renew domain'], collect($list->json('done'))->pluck('title')->all());

        // Unticked: back at the end of the open list.
        $this->actingAs($me)->patchJson("/api/todos/{$ids[1]}", ['done' => false])->assertOk();
        $this->assertEquals(['Call the printer re: proofs', 'Order toner', 'Renew domain'],
            collect($this->actingAs($me)->getJson('/api/todos')->json('open'))->pluck('title')->all());

        // Dragged into a new order.
        $this->actingAs($me)->putJson('/api/todos/order', ['ids' => [$ids[2], $ids[0], $ids[1]]])->assertOk()
            ->assertJsonPath('open.0.title', 'Order toner');

        // Clear done removes only the ticked ones.
        $this->actingAs($me)->patchJson("/api/todos/{$ids[2]}", ['done' => true]);
        $this->actingAs($me)->deleteJson('/api/todos/done')->assertNoContent();
        $this->assertEquals(2, Todo::count());

        $this->actingAs($me)->get('/my-work')->assertInertia(fn ($page) => $page->has('todos.open', 2)->has('todos.done', 0));
    }

    public function test_starred_ones_stay_at_the_top(): void
    {
        $me = User::factory()->teamMember()->create();
        foreach (['Call the printer', 'Renew domain', 'Order toner', 'File receipts'] as $title) {
            $this->actingAs($me)->postJson('/api/todos', ['title' => $title]);
        }
        $ids = Todo::orderBy('id')->pluck('id');
        $open = fn () => collect($this->actingAs($me)->getJson('/api/todos')->json('open'))->pluck('title')->all();

        // Starred: to the top, in their own order.
        $this->actingAs($me)->patchJson("/api/todos/{$ids[3]}", ['starred' => true])->assertOk()->assertJsonPath('starred', true);
        $this->actingAs($me)->patchJson("/api/todos/{$ids[1]}", ['starred' => true]);
        $this->assertEquals(['Renew domain', 'File receipts', 'Call the printer', 'Order toner'], $open());

        // Unstarred: back to its place among the rest.
        $this->actingAs($me)->patchJson("/api/todos/{$ids[1]}", ['starred' => false]);
        $this->assertEquals(['File receipts', 'Call the printer', 'Renew domain', 'Order toner'], $open());

        // Ticked and unticked, it stays starred.
        $this->actingAs($me)->patchJson("/api/todos/{$ids[3]}", ['done' => true]);
        $this->actingAs($me)->patchJson("/api/todos/{$ids[3]}", ['done' => false]);
        $this->assertSame('File receipts', $open()[0]);
    }

    public function test_a_to_do_list_is_private(): void
    {
        $me = User::factory()->teamMember()->create();
        $boss = User::factory()->create(['role' => 'super_admin']);
        $mine = $this->actingAs($me)->postJson('/api/todos', ['title' => 'Mine'])->json('id');
        $this->actingAs($me)->patchJson("/api/todos/{$mine}", ['done' => true]);

        // Nobody else sees it, even a super admin...
        $this->actingAs($boss)->getJson('/api/todos')->assertJsonCount(0, 'open')->assertJsonCount(0, 'done');
        $this->actingAs($boss)->get('/my-work')->assertInertia(fn ($page) => $page->has('todos.open', 0)->has('todos.done', 0));

        // ...or can change, reorder, clear or delete it.
        $this->actingAs($boss)->patchJson("/api/todos/{$mine}", ['title' => 'Theirs'])->assertNotFound();
        $this->actingAs($boss)->deleteJson("/api/todos/{$mine}")->assertNotFound();
        $this->actingAs($boss)->putJson('/api/todos/order', ['ids' => [$mine]])->assertOk();
        $this->actingAs($boss)->deleteJson('/api/todos/done')->assertNoContent();

        $todo = Todo::find($mine);
        $this->assertSame('Mine', $todo->title);
        $this->assertNotNull($todo->done_at);
    }

    public function test_a_to_do_needs_a_title(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/todos', ['title' => ''])->assertUnprocessable();
    }
}
