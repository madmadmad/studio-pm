<?php

use App\Http\Middleware\EnsureMcpUser;
use App\Mcp\Servers\StudioServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// The studio as an MCP server, for Claude (Settings → Connectors → Add custom
// connector: https://<app>/mcp/studio). Claude registers itself and asks a
// staff member to sign in and approve it (OAuth via Passport; the consent
// screen is resources/views/mcp/authorize.blade.php). Its tools act as that
// person, within their permissions (app/Mcp/Tools).

// OAuth discovery (.well-known/...) and client registration (/oauth/register,
// limited to Claude's redirect domains in config/mcp.php).
Route::middleware('throttle:30,1')->group(fn () => Mcp::oauthRoutes());

Mcp::web('/mcp/studio', StudioServer::class)
    ->middleware(['auth:api', EnsureMcpUser::class, 'throttle:120,1']);
