<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Services\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

// A conversation's messages: its history a page at a time, catching up
// after a dropped connection, and posting, editing, deleting and reacting.
class ChatMessageController extends Controller
{
    public function __construct(protected ChatService $chat) {}

    // Three ways in:
    //   (nothing)        the latest page
    //   ?before=ID       the page before that message (scrolling up)
    //   ?after=ID[&since=ISO]  everything newer than that message, plus any
    //                    older one changed since then (edited, deleted,
    //                    reacted to) -- a reconnecting tab's catch-up.
    // Deleted messages come too, shown as "deleted". `synced_at` is the
    // server's clock, for the next catch-up's `since`.
    public function index(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $data = $request->validate([
            'before' => ['nullable', 'integer'],
            'after' => ['nullable', 'integer'],
            'since' => ['nullable', 'date'],
        ]);
        $pageSize = config('chat.page_size');
        $syncedAt = now();
        $query = $conversation->messages()->withTrashed()->with(ChatMessage::displayRelations());

        if (isset($data['after'])) {
            $messages = $query
                ->where(fn ($q) => $q->where('id', '>', $data['after'])
                    ->when($data['since'] ?? null, fn ($q, $since) => $q->orWhere('updated_at', '>=', Carbon::parse($since))))
                ->orderBy('id')->limit($pageSize * 4 + 1)->get();
            // Too far behind to patch up -- the front end reloads the latest page.
            $hasMore = $messages->count() > $pageSize * 4;
            $messages = $messages->take($pageSize * 4);
        } else {
            $messages = $query
                ->when($data['before'] ?? null, fn ($q, $before) => $q->where('id', '<', $before))
                ->orderByDesc('id')->limit($pageSize + 1)->get();
            $hasMore = $messages->count() > $pageSize;
            $messages = $messages->take($pageSize)->reverse()->values();
        }

        return response()->json([
            'messages' => $messages->map->toChatArray()->values(),
            'has_more' => $hasMore,
            'synced_at' => $syncedAt->toIso8601String(),
        ]);
    }

    public function store(Request $request, Conversation $conversation)
    {
        $this->authorize('post', $conversation);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.config('chat.max_body_length'), 'required_without:attachments'],
            'client_id' => ['nullable', 'string', 'max:64'],
            ...static::attachmentRules(),
        ], ['body.required_without' => 'Write a message or attach a file.']);

        $message = $this->chat->post($conversation, $request->user(), $data['body'] ?? null, $request->file('attachments', []), $data['client_id'] ?? null);

        return response()->json($message->toChatArray($data['client_id'] ?? null), 201);
    }

    public function update(Request $request, ChatMessage $message)
    {
        $this->authorize('update', $message);

        $data = $request->validate([
            // A message with files may lose its words; one without can't.
            'body' => [$message->attachments()->exists() ? 'nullable' : 'required', 'string', 'max:'.config('chat.max_body_length')],
        ]);

        return response()->json($this->chat->edit($message, $data['body'] ?? null)->toChatArray());
    }

    public function destroy(ChatMessage $message)
    {
        $this->authorize('delete', $message);

        return response()->json($this->chat->delete($message)->toChatArray());
    }

    public function react(Request $request, ChatMessage $message)
    {
        $this->authorize('react', $message);

        $data = $request->validate([
            'emoji' => ['required', 'string', Rule::in(config('chat.reaction_emoji'))],
        ]);

        return response()->json($this->chat->toggleReaction($message, $request->user(), $data['emoji'])->toChatArray());
    }

    // Validated against each file's real (detected) type, never its name.
    protected static function attachmentRules(): array
    {
        $mimes = collect(config('message_attachments.allowed_mimes'))->flatten()->unique()->values()->all();

        return [
            'attachments' => ['array', 'max:'.config('chat.max_files_per_message')],
            'attachments.*' => ['file', 'max:'.config('chat.max_file_size_kb'), 'mimetypes:'.implode(',', $mimes)],
        ];
    }
}
