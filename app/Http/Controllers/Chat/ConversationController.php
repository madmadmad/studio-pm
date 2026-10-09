<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatService;
use App\Services\ChatUnread;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Chat's conversations: the ones you're in (the sidebar), browsing and
// joining channels, starting a channel or a direct message, and how far
// you've read. Staff only (routes/api.php).
class ConversationController extends Controller
{
    public function __construct(protected ChatService $chat) {}

    // The sidebar: every conversation you're in (but direct messages you've
    // closed), with your unread counts.
    public function index(Request $request)
    {
        return response()->json(static::summariesFor($request->user()));
    }

    // Every channel in the studio, to browse and join.
    public function channels(Request $request)
    {
        $this->authorize('viewAny', Conversation::class);
        $user = $request->user();

        return response()->json(
            Conversation::channels()->withCount('members')
                ->withExists(['members as is_member' => fn ($q) => $q->whereKey($user->id)])
                ->orderBy('name')->get()
                ->map(fn (Conversation $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'description' => $c->description,
                    'emoji' => $c->emoji,
                    'member_count' => $c->members_count,
                    'is_member' => (bool) $c->is_member,
                ])
        );
    }

    public function storeChannel(Request $request)
    {
        $this->authorize('create', Conversation::class);

        [$slug, $description, $emoji] = $this->validatedChannel($request);
        $channel = $this->chat->createChannel($request->user(), $slug, $description, $emoji);

        return response()->json($channel->load('members')->toSummaryArray(), 201);
    }

    // A new name, description or emoji. #general keeps its name -- it's where
    // everyone starts (ChatSeeder) -- but its description can change.
    public function updateChannel(Request $request, Conversation $conversation)
    {
        $this->authorize('update', $conversation);

        if ($conversation->slug === 'general') {
            $request->merge(['name' => 'general']);
        }
        [$slug, $description, $emoji] = $this->validatedChannel($request, $conversation);
        $this->chat->updateChannel($conversation, $slug, $description, $emoji);

        $counts = ChatUnread::forUser($request->user())[$conversation->id] ?? [];

        return response()->json($conversation->load('members')->toSummaryArray($counts));
    }

    // A channel's name is its slug: "Design Crit" is #design-crit. Unique,
    // other than the channel's own.
    protected function validatedChannel(Request $request, ?Conversation $channel = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            // In place of its # -- one from the picker's set.
            'emoji' => ['nullable', 'string', Rule::in(config('chat.reaction_emoji'))],
        ]);
        $slug = Str::slug($data['name']);
        validator(['name' => $slug], [
            'name' => ['required', Rule::unique('conversations', 'slug')->ignore($channel?->id)],
        ], ['name.required' => 'Give the channel a name with letters or numbers.', 'name.unique' => 'There is already a channel with that name.'])->validate();

        return [$slug, filled($data['description'] ?? null) ? trim($data['description']) : null, $data['emoji'] ?? null];
    }

    public function join(Request $request, Conversation $conversation)
    {
        $this->authorize('join', $conversation);
        $this->chat->join($conversation, $request->user());

        return response()->json($conversation->load('members')->toSummaryArray());
    }

    public function leave(Request $request, Conversation $conversation)
    {
        $this->authorize('leave', $conversation);
        $this->chat->leave($conversation, $request->user());

        return response()->noContent();
    }

    // The direct message with these people -- the one you've already got
    // with them, if any.
    public function direct(Request $request)
    {
        $this->authorize('create', Conversation::class);

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:8'],
            'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNull('deactivated_at')],
        ]);

        $conversation = $this->chat->findOrCreateDirect($request->user(), $data['user_ids']);
        $counts = ChatUnread::forUser($request->user())[$conversation->id] ?? [];

        return response()->json($conversation->load('members')->toSummaryArray($counts), $conversation->wasRecentlyCreated ? 201 : 200);
    }

    // Out of your sidebar, until there's something new in it.
    public function close(Request $request, Conversation $conversation)
    {
        $this->authorize('close', $conversation);
        $this->chat->close($conversation, $request->user());

        return response()->noContent();
    }

    // Seen up to this message (sent while the conversation's open and the
    // tab is in view).
    public function read(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $data = $request->validate([
            'message_id' => ['required', 'integer', Rule::exists('chat_messages', 'id')->where('conversation_id', $conversation->id)],
        ]);
        $this->chat->markRead($conversation, $request->user(), $data['message_id']);

        return response()->json(ChatUnread::forUser($request->user())[$conversation->id] ?? ['unread' => 0, 'mentions' => 0]);
    }

    // Your unread counts, per conversation and in total.
    public function unread(Request $request)
    {
        $counts = ChatUnread::forUser($request->user());

        return response()->json([
            'conversations' => (object) $counts,
            'total' => ['unread' => array_sum(array_column($counts, 'unread')), 'mentions' => array_sum(array_column($counts, 'mentions'))],
        ]);
    }

    public static function summariesFor(User $user): array
    {
        $counts = ChatUnread::forUser($user);

        return Conversation::shownTo($user)->with('members')->get()
            ->map(fn (Conversation $c) => $c->toSummaryArray($counts[$c->id] ?? []))
            ->all();
    }
}
