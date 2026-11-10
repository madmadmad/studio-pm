<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use App\Models\TodoCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// A person's own to-do list (My Work → To-do), in their own groups
// (TodoCategoryController). Every action is on the signed-in person's
// list only; anyone else's to-do is a 404, as if it weren't there, and a
// group of anyone else's can't be used.
class TodoController extends Controller
{
    // How many ticked ones the list keeps showing (newest first).
    const DONE_SHOWN = 50;

    // A to-do's link: any app's address (https:, mailto:, readdle-spark:
    // for an email copied from Spark), but never one that runs script.
    const LINK = 'regex:/^(?!\s*(javascript|data|vbscript):)\s*[a-z][a-z0-9+.\-]*:\S+\s*$/i';

    // Their groups in order, the open ones in their order (starred first;
    // the page sorts them into their groups, keeping it), then the most
    // recently ticked.
    public static function listFor(int $userId): array
    {
        return [
            'categories' => TodoCategory::where('user_id', $userId)->orderBy('position')->orderBy('id')->get(),
            'open' => Todo::where('user_id', $userId)->whereNull('done_at')->orderByDesc('starred')->orderBy('position')->orderBy('id')->get(),
            'done' => Todo::where('user_id', $userId)->whereNotNull('done_at')->orderByDesc('done_at')->orderByDesc('id')->limit(self::DONE_SHOWN)->get(),
        ];
    }

    public function index(Request $request)
    {
        return self::listFor($request->user()->id);
    }

    // New ones go at the end of the open list, in a group of theirs or none.
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'todo_category_id' => ['nullable', self::ownCategory($user->id)],
        ]);

        $todo = new Todo([
            'title' => trim($data['title']),
            'position' => self::nextPosition($user->id),
            'todo_category_id' => $data['todo_category_id'] ?? null,
        ]);
        $todo->user()->associate($user)->save();

        return $todo;
    }

    // A new title, a link set or cleared (null or blank), starred /
    // unstarred, moved to another group (or none)
    // -- to the end of it -- or ticked (`done`: true) / unticked --
    // unticked, it goes back to the end of its group.
    public function update(Request $request, Todo $todo)
    {
        $this->own($request, $todo);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'url' => ['sometimes', 'nullable', 'string', 'max:2000', self::LINK],
            'starred' => ['sometimes', 'boolean'],
            'done' => ['sometimes', 'boolean'],
            'todo_category_id' => ['sometimes', 'nullable', self::ownCategory($todo->user_id)],
        ]);

        if (isset($data['title'])) {
            $todo->title = trim($data['title']);
        }
        if (array_key_exists('url', $data)) {
            $todo->url = trim((string) $data['url']) ?: null;
        }
        if (array_key_exists('starred', $data)) {
            $todo->starred = $data['starred'];
        }
        if (array_key_exists('todo_category_id', $data) && $data['todo_category_id'] != $todo->todo_category_id) {
            $todo->todo_category_id = $data['todo_category_id'];
            $todo->position = self::nextPosition($todo->user_id);
        }
        if (array_key_exists('done', $data)) {
            if ($data['done'] && ! $todo->done_at) {
                $todo->done_at = now();
            } elseif (! $data['done'] && $todo->done_at) {
                $todo->done_at = null;
                $todo->position = self::nextPosition($todo->user_id);
            }
        }
        $todo->save();

        return $todo;
    }

    // Saves the open list's order after a drag: `ids` top to bottom. A
    // to-do dragged into another group comes as `move` (its id and the
    // group's, or null for none).
    public function reorder(Request $request)
    {
        $userId = $request->user()->id;
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'move' => ['sometimes', 'array'],
            'move.id' => ['required_with:move', 'integer'],
            'move.todo_category_id' => ['present_with:move', 'nullable', self::ownCategory($userId)],
        ]);

        DB::transaction(function () use ($data, $userId) {
            if (isset($data['move'])) {
                Todo::where('user_id', $userId)->whereKey($data['move']['id'])->update(['todo_category_id' => $data['move']['todo_category_id']]);
            }
            foreach ($data['ids'] as $position => $id) {
                Todo::where('user_id', $userId)->whereKey($id)->update(['position' => $position]);
            }
        });

        return self::listFor($userId);
    }

    public function destroy(Request $request, Todo $todo)
    {
        $this->own($request, $todo);
        $todo->delete();

        return response()->noContent();
    }

    // "Clear done": removes every ticked one.
    public function clearDone(Request $request)
    {
        Todo::where('user_id', $request->user()->id)->whereNotNull('done_at')->delete();

        return response()->noContent();
    }

    // After every open one of theirs.
    private static function nextPosition(int $userId): int
    {
        return (Todo::where('user_id', $userId)->whereNull('done_at')->max('position') ?? -1) + 1;
    }

    // One of this person's own groups.
    private static function ownCategory(int $userId)
    {
        return Rule::exists('todo_categories', 'id')->where('user_id', $userId);
    }

    private function own(Request $request, Todo $todo): void
    {
        abort_unless($todo->user_id === $request->user()->id, 404);
    }
}
