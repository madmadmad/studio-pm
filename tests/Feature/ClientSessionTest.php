<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// An app left open: it checks in to keep its session, learns plainly when
// it's been signed out, and reports what went wrong to the log.
class ClientSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_checking_in_answers_signed_in_or_signed_out(): void
    {
        $me = User::factory()->teamMember()->create();

        $this->actingAs($me)->getJson('/api/session')->assertOk()->assertJsonPath('user_id', $me->id);

        auth()->forgetGuards();
        $this->getJson('/api/session')->assertUnauthorized();
    }

    public function test_the_app_reports_what_went_wrong_to_the_log(): void
    {
        Log::spy();
        $me = User::factory()->teamMember()->create();

        $this->actingAs($me)->postJson('/api/session/events', [
            'installed' => true,
            'events' => [
                ['event' => 'socket_down', 'detail' => ['state' => 'unavailable'], 'at' => '2026-10-15T14:02:00Z', 'url' => '/chat/4'],
                ['event' => 'request_failed', 'detail' => ['method' => 'POST', 'path' => '/api/todos', 'status' => 419]],
            ],
        ])->assertNoContent();

        Log::shouldHaveReceived('warning')->with('Client: socket_down', \Mockery::on(fn ($context) => $context['user_id'] === $me->id
            && $context['detail'] === ['state' => 'unavailable']
            && $context['url'] === '/chat/4'
            && $context['installed'] === true))->once();
        Log::shouldHaveReceived('warning')->with('Client: request_failed', \Mockery::any())->once();
    }

    public function test_reports_are_bounded(): void
    {
        $me = User::factory()->teamMember()->create();

        $this->actingAs($me)->postJson('/api/session/events', ['events' => array_fill(0, 21, ['event' => 'x'])])->assertUnprocessable();
        $this->actingAs($me)->postJson('/api/session/events', ['events' => [['event' => str_repeat('x', 61)]]])->assertUnprocessable();
    }

    public function test_the_accountant_can_check_in_too(): void
    {
        $accountant = User::factory()->create(['role' => User::ROLE_ACCOUNTANT]);

        $this->actingAs($accountant)->getJson('/api/session')->assertOk();
        $this->actingAs($accountant)->postJson('/api/session/events', ['events' => [['event' => 'woke_from_sleep']]])->assertNoContent();
    }
}
