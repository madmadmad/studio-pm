<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\TodoCategory;
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

    public function test_groups_named_colored_ordered_and_deleted(): void
    {
        $me = User::factory()->teamMember()->create();
        $hot = $this->actingAs($me)->postJson('/api/todo-categories', ['name' => '  Hot  '])->assertCreated()
            ->assertJsonPath('name', 'Hot')->assertJsonPath('color', '#2F6FDB')->json('id');
        $proposals = $this->actingAs($me)->postJson('/api/todo-categories', ['name' => 'Proposals'])->assertJsonPath('color', '#1E9E80')->json('id');
        $waiting = $this->actingAs($me)->postJson('/api/todo-categories', ['name' => 'Waiting', 'color' => '#ff6600'])->assertJsonPath('color', '#FF6600')->json('id');
        $categories = fn () => collect($this->actingAs($me)->getJson('/api/todos')->json('categories'));

        // Renamed, recolored, folded.
        $this->actingAs($me)->patchJson("/api/todo-categories/{$waiting}", ['name' => 'Waiting for feedback', 'color' => '#a1b2c3', 'collapsed' => true])->assertOk();
        $this->actingAs($me)->patchJson("/api/todo-categories/{$waiting}", ['color' => 'neon'])->assertUnprocessable();
        $this->actingAs($me)->patchJson("/api/todo-categories/{$waiting}", ['color' => '#12345'])->assertUnprocessable();
        $this->actingAs($me)->patchJson("/api/todo-categories/{$waiting}", ['name' => '   '])->assertUnprocessable();
        $this->assertEquals(['name' => 'Waiting for feedback', 'color' => '#A1B2C3', 'collapsed' => true],
            collect($categories()->firstWhere('id', $waiting))->only('name', 'color', 'collapsed')->all());

        // Dragged into a new order.
        $this->actingAs($me)->putJson('/api/todo-categories/order', ['ids' => [$waiting, $hot, $proposals]])->assertOk();
        $this->assertEquals(['Waiting for feedback', 'Hot', 'Proposals'], $categories()->pluck('name')->all());

        // Deleted: its to-dos stay, in no group.
        $todo = $this->actingAs($me)->postJson('/api/todos', ['title' => 'Send the deck', 'todo_category_id' => $proposals])
            ->assertJsonPath('todo_category_id', $proposals)->json('id');
        $this->actingAs($me)->deleteJson("/api/todo-categories/{$proposals}")->assertNoContent();
        $this->assertNull(Todo::find($todo)->todo_category_id);
        $this->assertEquals(2, $categories()->count());
    }

    public function test_to_dos_move_between_groups(): void
    {
        $me = User::factory()->teamMember()->create();
        $hot = $this->actingAs($me)->postJson('/api/todo-categories', ['name' => 'Hot'])->json('id');
        $a = $this->actingAs($me)->postJson('/api/todos', ['title' => 'A'])->json('id');
        $b = $this->actingAs($me)->postJson('/api/todos', ['title' => 'B', 'todo_category_id' => $hot])->json('id');
        $c = $this->actingAs($me)->postJson('/api/todos', ['title' => 'C'])->json('id');

        // "Move to": to the end of the group.
        $this->actingAs($me)->patchJson("/api/todos/{$a}", ['todo_category_id' => $hot])->assertOk()->assertJsonPath('todo_category_id', $hot);
        $inHot = fn () => collect($this->actingAs($me)->getJson('/api/todos')->json('open'))->where('todo_category_id', $hot)->pluck('title')->values()->all();
        $this->assertEquals(['B', 'A'], $inHot());

        // Dragged from no group onto the top of Hot.
        $this->actingAs($me)->putJson('/api/todos/order', ['ids' => [$c, $b, $a], 'move' => ['id' => $c, 'todo_category_id' => $hot]])->assertOk();
        $this->assertEquals(['C', 'B', 'A'], $inHot());

        // And back out to none.
        $this->actingAs($me)->patchJson("/api/todos/{$c}", ['todo_category_id' => null])->assertOk()->assertJsonPath('todo_category_id', null);
        $this->assertEquals(['B', 'A'], $inHot());

        // Starred rises to the top of its group.
        $this->actingAs($me)->patchJson("/api/todos/{$a}", ['starred' => true]);
        $this->assertEquals(['A', 'B'], $inHot());
    }

    public function test_groups_are_private(): void
    {
        $me = User::factory()->teamMember()->create();
        $other = User::factory()->teamMember()->create();
        $mine = $this->actingAs($me)->postJson('/api/todo-categories', ['name' => 'Mine'])->json('id');
        $theirTodo = $this->actingAs($other)->postJson('/api/todos', ['title' => 'Theirs'])->json('id');

        $this->actingAs($other)->getJson('/api/todos')->assertJsonCount(0, 'categories');
        $this->actingAs($other)->patchJson("/api/todo-categories/{$mine}", ['name' => 'Taken'])->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/todo-categories/{$mine}")->assertNotFound();
        $this->actingAs($other)->putJson('/api/todo-categories/order', ['ids' => [$mine]])->assertOk();

        // Nor can they put their own to-dos in it.
        $this->actingAs($other)->postJson('/api/todos', ['title' => 'Sneaky', 'todo_category_id' => $mine])->assertUnprocessable();
        $this->actingAs($other)->patchJson("/api/todos/{$theirTodo}", ['todo_category_id' => $mine])->assertUnprocessable();
        $this->actingAs($other)->putJson('/api/todos/order', ['ids' => [$theirTodo], 'move' => ['id' => $theirTodo, 'todo_category_id' => $mine]])->assertUnprocessable();

        $this->assertSame('Mine', TodoCategory::find($mine)->name);
        $this->assertSame(0, Todo::whereNotNull('todo_category_id')->count());
    }

    public function test_a_to_do_carries_a_link(): void
    {
        $me = User::factory()->teamMember()->create();
        $id = $this->actingAs($me)->postJson('/api/todos', ['title' => 'Reply to Dana'])->json('id');

        // Spark's own links, web links, mail links.
        foreach (['readdle-spark://bl=QTpiaWxs', 'https://sparkmailapp.com/share/abc', 'mailto:dana@example.com'] as $url) {
            $this->actingAs($me)->patchJson("/api/todos/{$id}", ['url' => "  {$url} "])->assertOk()->assertJsonPath('url', $url);
        }
        $this->assertSame('mailto:dana@example.com', $this->actingAs($me)->getJson('/api/todos')->json('open.0.url'));

        // Never one that runs script, nor something that isn't a link.
        foreach (['javascript:alert(1)', ' JavaScript:alert(1)', 'data:text/html,hi', 'just some words'] as $bad) {
            $this->actingAs($me)->patchJson("/api/todos/{$id}", ['url' => $bad])->assertUnprocessable();
        }

        // Cleared.
        $this->actingAs($me)->patchJson("/api/todos/{$id}", ['url' => null])->assertOk()->assertJsonPath('url', null);
        $this->actingAs($me)->patchJson("/api/todos/{$id}", ['url' => 'https://example.com'])->assertOk();
        $this->actingAs($me)->patchJson("/api/todos/{$id}", ['url' => ''])->assertOk()->assertJsonPath('url', null);

        // Someone else's to-do: not theirs to link.
        $this->actingAs(User::factory()->teamMember()->create())->patchJson("/api/todos/{$id}", ['url' => 'https://example.com'])->assertNotFound();
    }

    public function test_a_to_do_needs_a_title(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/todos', ['title' => ''])->assertUnprocessable();
    }
}
