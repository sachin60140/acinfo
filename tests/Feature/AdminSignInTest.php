<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Signing in to the office, and Remember me.
 *
 * Every office page also checks that the session, or the Remember me cookie,
 * still carries the password the account has now — that is what lets a changed
 * password sign the other devices out (see AdminPasswordTest). These are the
 * ordinary sign-ins that check must never get in the way of: the form, the
 * cookie on its own a day later, a session already open when the check
 * arrived, and signing out.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class AdminSignInTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'the-office-one-8';

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Sign In Test Admin';
        $user->email = 'signin-admin-'.uniqid().'@example.com';
        $user->password = Hash::make(self::PASSWORD);
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    private function signIn(User $user, string $password, bool $remember = false)
    {
        return $this->post('admin-login', array_filter([
            'email' => $user->email,
            'password' => $password,
            'remember' => $remember ? 'true' : null,
        ]));
    }

    private function recaller(): string
    {
        return Auth::guard()->getRecallerName();
    }

    /** A different browser: see the note on the same method in AdminPasswordTest. */
    private function anotherDevice(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->app['cookie']->flushQueuedCookies();
    }

    /** The same browser's next page: see the note on it in AdminPasswordTest. */
    private function nextPage(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_the_right_password_gets_in(): void
    {
        $user = $this->admin();

        $this->signIn($user, self::PASSWORD)->assertRedirect('admin/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->nextPage();
        $this->get('admin/dashboard')->assertOk();
        $this->nextPage();
        $this->get('admin/files')->assertOk();

        // The sign-in page steps aside for somebody already in.
        $this->nextPage();
        $this->get('/admin')->assertRedirect('admin/dashboard');
    }

    /**
     * Nobody is signed out by this check arriving. A session signed in before
     * the office pages began checking has no password noted in it; the first
     * office page it opens notes the one the account has, and it carries on.
     * The sign-in page, which checks too, steps aside for it as before.
     */
    public function test_a_session_from_before_the_check_carries_on(): void
    {
        $user = $this->admin();

        $this->withSession([Auth::guard()->getName() => $user->id])
            ->get('admin/dashboard')
            ->assertOk();

        $this->nextPage();
        $this->get('admin/files')->assertOk();

        $this->flushSession();
        $this->nextPage();
        $this->withSession([Auth::guard()->getName() => $user->id])
            ->get('/admin')
            ->assertRedirect('admin/dashboard');

        $this->nextPage();
        $this->get('admin/dashboard')->assertOk();
    }

    public function test_the_wrong_password_does_not(): void
    {
        $user = $this->admin();

        $this->from('/admin')
            ->signIn($user, 'not-the-password')
            ->assertRedirect('/admin')
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->get('admin/dashboard')->assertRedirect(url('/admin'));
    }

    public function test_without_remember_me_there_is_no_cookie_to_come_back_with(): void
    {
        $user = $this->admin();

        $response = $this->signIn($user, self::PASSWORD);

        $this->assertNull($response->getCookie($this->recaller()));
    }

    /**
     * The point of Remember me: the browser closed, the session long gone, and
     * the cookie alone is enough. It is checked against the password on every
     * office page now, and must pass while the password is the one it was
     * issued under.
     */
    public function test_remember_me_gets_back_in_with_only_the_cookie(): void
    {
        $user = $this->admin();

        $cookie = $this->signIn($user, self::PASSWORD, remember: true)
            ->assertRedirect('admin/dashboard')
            ->getCookie($this->recaller());

        $this->assertNotNull($cookie);
        $this->assertGreaterThan(now()->addDays(300)->getTimestamp(), $cookie->getExpiresTime());

        $this->anotherDevice();

        $this->withCookie($this->recaller(), $cookie->getValue())
            ->get('admin/dashboard')
            ->assertOk();

        $this->assertAuthenticatedAs($user);

        // And on the pages after it, now from the session it started.
        $this->nextPage();
        $this->get('admin/files')->assertOk();
    }

    public function test_signing_out_lets_go_of_the_session_and_the_cookie(): void
    {
        $user = $this->admin();

        $cookie = $this->signIn($user, self::PASSWORD, remember: true)->getCookie($this->recaller());

        $this->post(route('adminlogout'))->assertRedirect('/admin');

        $this->assertGuest();
        $this->get('admin/dashboard')->assertRedirect(url('/admin'));

        // Signing out retires the token in the cookie, here and anywhere else
        // it was copied to.
        $this->anotherDevice();
        $this->withCookie($this->recaller(), $cookie->getValue())
            ->get('admin/dashboard')
            ->assertRedirect(url('/admin'));
    }
}
