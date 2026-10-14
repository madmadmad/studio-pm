<?php

namespace App\Http\Controllers;

use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// A person's own to-do list (My Work → To-do). Every action is on the
// signed-in person's list only; anyone else's to-do is a 404, as if it
// weren't there.
class TodoController extends Controller
{
    // How many ticked ones the list keeps showing (newest first).
    const DONE_SHOWN = 50;

    // Open ones in their order, then the most recently ticked.
    public static function listFor(int $userId): array
    {
        return [
            'open' => Todo::where('user_id', $userId)->whereNull('done_at')->orderBy('position')->orderBy('id')->get(),
            'done' => Todo::where('user_id', $userId)->whereNotNull('done_at')->orderByDesc('done_at')->orderByDesc('id')->limit(self::DONE_SHOWN)->get(),
        ];
    }

    public function index(Request $request)
    {
        return self::listFor($request->user()->id);
    }

    // New ones go at the end of the open list.
    public function store(Request $request)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:500']]);
        $user = $request->user();
        $position = (Todo::where('user_id', $user->id)->whereNull('done_at')->max('position') ?? -1) + 1;

        $todo = new Todo(['title' => trim($data['title']), 'position' => $position]);
        $todo->user()->associate($user)->save();

        return $todo;
    }

    // A new title, or ticked (`done`: true) / unticked -- unticked, it
    // goes back to the end of the open list.
    public function update(Request $request, Todo $todo)
    {
        $this->own($request, $todo);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'done' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['title'])) {
            $todo->title = trim($data['title']);
        }
        if (array_key_exists('done', $data)) {
            if ($data['done'] && ! $todo->done_at) {
                $todo->done_at = now();
            } elseif (! $data['done'] && $todo->done_at) {
                $todo->done_at = null;
                $todo->position = (Todo::where('user_id', $todo->user_id)->whereNull('done_at')->max('position') ?? -1) + 1;
            }
        }
        $todo->save();

        return $todo;
    }

    // Saves the open list's order after a drag: `ids` top to bottom.
    public function reorder(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        $userId = $request->user()->id;

        DB::transaction(function () use ($data, $userId) {
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

    private function own(Request $request, Todo $todo): void
    {
        abort_unless($todo->user_id === $request->user()->id, 404);
    }
}
