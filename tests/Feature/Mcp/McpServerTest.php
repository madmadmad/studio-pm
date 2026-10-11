<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Support\TrustedDevice;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use PragmaRX\Google2FA\Google2FA;

// The MCP server over HTTP (routes/ai.php): a Passport token for a staff
// member gets in; nobody else does. Claude registers itself (only with
// Claude's redirect domains) and a signed-in staff member approves it.
class McpServerTest extends McpTestCase
{
    private function listTools()
    {
        return $this->postJson('/mcp/studio', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    }

    public function test_without_a_token_it_asks_for_oauth(): void
    {
        $this->listTools()
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/studio').'"');

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
            ->assertJsonPath('registration_endpoint', url('/oauth/register'));
    }

    public function test_a_staff_members_token_lists_the_tools_and_nothing_that_sends_or_deletes(): void
    {
        Passport::actingAs($this->sam, ['mcp:use'], 'api');

        $names = collect($this->listTools()->assertOk()->json('result.tools'))->pluck('name');

        $this->assertEqualsCanonicalizing([
            'list_projects', 'get_project', 'list_companies', 'list_contacts', 'list_tasks',
            'list_unbilled_time', 'create_task', 'update_task', 'create_subtask', 'update_subtask', 'log_time', 'create_proposal_draft',
        ], $names->all());
        $this->assertTrue($names->every(fn ($name) => ! preg_match('/send|accept|email|delete|destroy|remove/', $name)));
    }

    public function test_the_accountant_is_refused(): void
    {
        Passport::actingAs(User::factory()->create(['role' => User::ROLE_ACCOUNTANT]), ['mcp:use'], 'api');

        $this->listTools()->assertForbidden();
    }

    public function test_a_deactivated_member_is_refused(): void
    {
        $this->sam->forceFill(['deactivated_at' => now()])->save();
        Passport::actingAs($this->sam, ['mcp:use'], 'api');

        $this->listTools()->assertForbidden();
    }

    public function test_staff_without_two_factor_are_refused(): void
    {
        Passport::actingAs(User::factory()->teamMember()->withoutTwoFactor()->create(), ['mcp:use'], 'api');

        $this->listTools()->assertForbidden();
    }

    public function test_only_claude_may_register_as_a_client(): void
    {
        $this->postJson('/oauth/register', ['client_name' => 'Evil', 'redirect_uris' => ['https://evil.example/callback']])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');
        $this->postJson('/oauth/register', ['client_name' => 'Sneaky', 'redirect_uris' => ['https://claude.ai.evil.example/callback']])
            ->assertStatus(400);

        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])
            ->assertSuccessful()
            ->assertJsonStructure(['client_id']);
    }

    private function authorizeUrl(): string
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback'], confidential: false);

        return '/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function test_a_signed_in_staff_member_sees_the_consent_screen(): void
    {
        $url = $this->authorizeUrl();

        $this->get($url)->assertRedirect('/login');

        $this->actingAs($this->sam, 'web')->get($url)
            ->assertOk()
            ->assertSee('Allow Claude to use')
            ->assertSee('never send')
            ->assertSee('Sam Staff');
    }

    public function test_the_accountant_cannot_approve(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ACCOUNTANT]), 'web')->get($this->authorizeUrl())->assertForbidden();
    }

    public function test_staff_without_two_factor_set_it_up_before_approving(): void
    {
        $this->actingAs(User::factory()->teamMember()->withoutTwoFactor()->create(), 'web')->get($this->authorizeUrl())->assertRedirect('/security/setup');
    }

    // Signing in on the way there (Inertia's sign-in pages) goes back to it
    // as a full page, not inside Inertia.
    public function test_signing_in_returns_to_the_consent_screen_as_a_full_page(): void
    {
        $url = $this->authorizeUrl();
        $this->get($url)->assertRedirect('/login');
        $this->sam->forceFill(['password' => 'a-known-password'])->save();
        $this->withCookie(TrustedDevice::COOKIE, '')->post('/login', ['email' => $this->sam->email, 'password' => 'a-known-password'], ['X-Inertia' => 'true'])
            ->assertRedirect('/two-factor-challenge');

        $location = $this->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP')], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->headers->get('X-Inertia-Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $actual);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $expected);
        $this->assertStringStartsWith(url('/oauth/authorize?'), $location);
        $this->assertEquals($expected, $actual);
    }
}
