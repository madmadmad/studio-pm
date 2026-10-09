<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Giphy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;

// The GIF picker's search (GIPHY's trending with no query), through the
// server so the API key never reaches a browser.
class GifController extends Controller
{
    public function index(Request $request, Giphy $giphy)
    {
        $this->authorize('viewAny', Conversation::class);
        abort_unless(Giphy::enabled(), 404);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:4999'],
        ]);

        try {
            return response()->json($giphy->search($data['q'] ?? null, $data['offset'] ?? 0));
        } catch (RequestException|ConnectionException) {
            return response()->json(['message' => "GIPHY isn't answering right now."], 502);
        }
    }
}
