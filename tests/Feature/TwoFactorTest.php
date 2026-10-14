<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TrustedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

// Two-factor for staff: required, asked for after the password, skippable
// for 30 days on a remembered device, and reset by a super admin when
// someone loses their phone. The accountant (emailed links) isn't asked.
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP'; // the factory's

    private function code(): string
    {
        return (new Google2FA)->getCurrentOtp(self::SECRET);
    }

    private function signIn(User $user, array $cookies = [])
    {
        return $this->withCookies($cookies)->post('/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function signOut(): void
    {
        auth()->forgetGuards();
        $this->app['session']->flush();
    }

    public function test_staff_without_two_factor_are_sent_to_set_it_up(): void
    {
        $sam = User::factory()->teamMember()->withoutTwoFactor()->create();

        $this->actingAs($sam, 'web')->get('/projects')->assertRedirect('/security/setup');
        $this->actingAs($sam, 'web')->get('/security/setup')->assertOk()->assertInertia(fn ($page) => $page->component('Security/Setup'));
        $this->actingAs($sam)->getJson('/api/users')->assertForbidden();
        $this->actingAs($sam)->postJson('/api/companies', ['name' => 'Acme'])->assertForbidden();
    }

    public function test_setting_it_up_opens_the_app(): void
    {
        $sam = User::factory()->teamMember()->withoutTwoFactor()->create();
        $this->actingAs($sam, 'web')->withSession(['auth.password_confirmed_at' => time()]);

        $this->postJson('/user/two-factor-authentication')->assertOk();
        $this->getJson('/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg', 'url']);
        $secret = $this->getJson('/user/two-factor-secret-key')->json('secretKey');
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk();

        $this->assertNotNull($sam->fresh()->two_factor_confirmed_at);
        $this->get('/projects')->assertOk();
        $this->get('/security/setup')->assertRedirect('/');
    }

    public function test_the_device_it_was_set_up_on_is_remembered(): void
    {
        $sam = User::factory()->teamMember()->withoutTwoFactor()->create();
        $this->actingAs($sam, 'web')->withSession(['auth.password_confirmed_at' => time()]);

        $this->postJson('/user/two-factor-authentication')->assertOk();
        $secret = $this->getJson('/user/two-factor-secret-key')->json('secretKey');
        $cookie = $this->postJson('/user/confirmed-two-factor-authentication', [
            'code' => (new Google2FA)->getCurrentOtp($secret),
            'remember_device' => true,
        ])->assertOk()->getCookie(TrustedDevice::COOKIE);
        $this->assertNotNull($cookie);
        $this->signOut();

        // The next day: password only.
        $this->travel(1)->days();
        $this->signIn($sam, [TrustedDevice::COOKIE => $cookie->getValue()])->assertRedirect('/');
        $this->assertAuthenticatedAs($sam, 'web');
    }

    public function test_setting_it_up_without_remember_sets_no_cookie(): void
    {
        $sam = User::factory()->teamMember()->withoutTwoFactor()->create();
        $this->actingAs($sam, 'web')->withSession(['auth.password_confirmed_at' => time()]);

        $this->postJson('/user/two-factor-authentication')->assertOk();
        $secret = $this->getJson('/user/two-factor-secret-key')->json('secretKey');
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()
            ->assertCookieMissing(TrustedDevice::COOKIE);
    }

    public function test_the_password_is_followed_by_a_code(): void
    {
        $sam = User::factory()->teamMember()->create();

        $this->signIn($sam)->assertRedirect('/two-factor-challenge');
        $this->assertGuest('web');

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest('web');

        $this->post('/two-factor-challenge', ['code' => $this->code()])->assertRedirect('/');
        $this->assertAuthenticatedAs($sam, 'web');
    }

    public function test_a_remembered_device_skips_the_code_for_30_days(): void
    {
        $sam = User::factory()->teamMember()->create();

        $this->signIn($sam);
        $response = $this->post('/two-factor-challenge', ['code' => $this->code(), 'remember_device' => true]);
        $cookie = $response->getCookie(TrustedDevice::COOKIE);
        $this->assertNotNull($cookie);
        $this->signOut();

        $this->signIn($sam, [TrustedDevice::COOKIE => $cookie->getValue()])->assertRedirect('/');
        $this->assertAuthenticatedAs($sam, 'web');
        $this->signOut();

        // Not past 30 days...
        $this->travel(31)->days();
        $this->signIn($sam, [TrustedDevice::COOKIE => $cookie->getValue()])->assertRedirect('/two-factor-challenge');
        $this->signOut();
        $this->travelBack();

        // ...nor for anyone else...
        $alex = User::factory()->teamMember()->create();
        $this->signIn($alex, [TrustedDevice::COOKIE => $cookie->getValue()])->assertRedirect('/two-factor-challenge');
        $this->signOut();

        // ...nor once the authenticator changes.
        $sam->forceFill(['two_factor_secret' => encrypt('KRUGS4ZANFZSA5DI')])->save();
        $this->signIn($sam, [TrustedDevice::COOKIE => $cookie->getValue()])->assertRedirect('/two-factor-challenge');
    }

    public function test_not_ticking_remember_sets_no_cookie(): void
    {
        $sam = User::factory()->teamMember()->create();

        $this->signIn($sam);
        $this->post('/two-factor-challenge', ['code' => $this->code()])->assertCookieMissing(TrustedDevice::COOKIE);
    }

    public function test_a_recovery_code_works_once(): void
    {
        $sam = User::factory()->teamMember()->create();

        $this->signIn($sam);
        $this->post('/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertRedirect('/');
        $this->assertAuthenticatedAs($sam, 'web');
        $this->signOut();

        $this->signIn($sam);
        $this->post('/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest('web');
    }

    public function test_a_super_admin_can_reset_someones_two_factor(): void
    {
        $admin = User::factory()->create();
        $sam = User::factory()->teamMember()->create();
        DB::table('sessions')->insert(['id' => 'sams-session', 'user_id' => $sam->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)->postJson("/api/users/{$sam->id}/reset-two-factor")
            ->assertOk()
            ->assertJsonPath('two_factor_enabled', false);

        $sam->refresh();
        $this->assertNull($sam->two_factor_secret);
        $this->assertNull($sam->two_factor_confirmed_at);
        $this->assertFalse(DB::table('sessions')->where('user_id', $sam->id)->exists());

        // Signs in with the password alone, straight to setting it up.
        $this->signOut();
        $this->signIn($sam)->assertRedirect('/');
        $this->get('/projects')->assertRedirect('/security/setup');
    }

    public function test_only_a_super_admin_can_reset_it_and_not_their_own(): void
    {
        $admin = User::factory()->create();
        $sam = User::factory()->teamMember()->create();
        $alex = User::factory()->teamMember()->create();

        $this->actingAs($sam)->postJson("/api/users/{$alex->id}/reset-two-factor")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/users/{$admin->id}/reset-two-factor")->assertStatus(422);
        $this->assertNotNull($alex->fresh()->two_factor_confirmed_at);
    }

    public function test_the_accountant_is_not_asked(): void
    {
        $casey = User::factory()->withoutTwoFactor()->create(['role' => User::ROLE_ACCOUNTANT]);

        $this->actingAs($casey, 'web')->get('/bookkeeping')->assertOk();
    }

    public function test_it_can_be_switched_off_for_the_whole_app(): void
    {
        config(['auth.require_two_factor' => false]);
        $sam = User::factory()->teamMember()->withoutTwoFactor()->create();

        $this->actingAs($sam, 'web')->get('/projects')->assertOk();
    }

    public function test_profile_shows_security(): void
    {
        $sam = User::factory()->teamMember()->create();

        $this->actingAs($sam, 'web')->get('/profile')->assertInertia(fn ($page) => $page
            ->where('security.twoFactor', true)
            ->where('security.passkeys', [])
            ->where('auth.user.two_factor_enabled', true)
            ->missing('auth.user.two_factor_secret')
        );
    }
}
