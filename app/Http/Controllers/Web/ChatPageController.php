<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Chat\ConversationController;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

// The Chat page: the sidebar of conversations, and the open one. Its
// messages load from the API (ChatMessageController), as does everything
// that changes after the page is drawn.
class ChatPageController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Conversation::class);

        return $this->render($request, null);
    }

    public function show(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        return $this->render($request, $conversation);
    }

    protected function render(Request $request, ?Conversation $conversation)
    {
        return Inertia::render('Chat/Index', [
            'conversations' => ConversationController::summariesFor($request->user()),
            'conversationId' => $conversation?->id,
            // Who you can message and mention.
            'staff' => User::whereNull('deactivated_at')->orderBy('name')->get(['id', 'name', 'avatar_path', 'job_title'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'avatar_url' => $u->avatar_url, 'job_title' => $u->job_title]),
            'limits' => [
                'maxFiles' => (int) config('chat.max_files_per_message'),
                'maxFileSizeKb' => (int) config('chat.max_file_size_kb'),
                'maxBodyLength' => (int) config('chat.max_body_length'),
            ],
        ]);
    }
}
