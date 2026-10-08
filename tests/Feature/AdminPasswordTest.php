<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminMiddleware;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * An admin changing their own password.
 *
 * There is one admin account on this installation, so the way it is lost is not
 * a guessed password — it is an office machine left signed in. That is why the
 * current password is asked for even though the session already says who this
 * is, and it is the rule most worth a test: everything else here fails loudly,
 * and that one fails by quietly letting somebody through.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class AdminPasswordTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'the-current-one-8';

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Password Test Admin';
        $user->email = 'pw-admin-'.uniqid().'@example.com';
        $user->password = Hash::make(self::PASSWORD);
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    private function change(User $user, array $payload)
    {
        return $this->actingAs($user)->post(route('adminpassword'), $payload);
    }

    /**
     * Signing in by hand, the way the sign-in page does it, rather than with
     * actingAs(): the remember cookie and the session it writes are what is
     * being tested.
     */
    private function signIn(User $user, string $password, bool $remember = false)
    {
        return $this->post('admin-login', array_filter([
            'email' => $user->email,
            'password' => $password,
            'remember' => $remember ? 'true' : null,
        ]));
    }

    private function newPassword(): array
    {
        return [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ];
    }

    /** The name of the Remember me cookie. */
    private function recaller(): string
    {
        return Auth::guard()->getRecallerName();
    }

    /**
     * The next request comes from a different browser. Within one test the
     * test client keeps a single session, signed-in user, set of withCookie()
     * cookies and queue of cookies for the response, across every request; all
     * four are let go of, so after this a request carries only what the test
     * hands it.
     */
    private function anotherDevice(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->app['cookie']->flushQueuedCookies();
    }

    /**
     * The same browser's next page. A server starts every request knowing
     * nobody, until the session or the Remember me cookie says who it is; the
     * test client keeps whoever the last request signed in, and would let a
     * page through on that alone. The session and the cookies stay.
     */
    private function nextPage(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_an_admin_can_change_their_own_password(): void
    {
        $user = $this->admin();

        $this->change($user, [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertRedirect('admin/dashboard');

        $stored = $user->fresh()->password;

        $this->assertTrue(Hash::check('a-brand-new-one', $stored));

        /*
         * And never the plain text. Held here by two things at once — this
         * controller hashes, and the model casts 'password' => 'hashed' — so
         * removing either alone changes nothing. The property is what matters,
         * so the property is what is asserted rather than the mechanism.
         */
        $this->assertNotSame('a-brand-new-one', $stored);
        $this->assertStringStartsWith('$2y$', $stored);
    }

    public function test_the_current_password_is_required(): void
    {
        $user = $this->admin();

        $this->change($user, [
            'current_password' => 'not-the-right-one',
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(
            Hash::check(self::PASSWORD, $user->fresh()->password),
            'the password is unchanged'
        );
    }

    public function test_the_new_password_has_to_be_confirmed(): void
    {
        $user = $this->admin();

        $this->change($user, [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-typo',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_a_short_password_is_refused(): void
    {
        $user = $this->admin();

        $this->change($user, [
            'current_password' => self::PASSWORD,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_the_new_password_must_differ_from_the_old_one(): void
    {
        $user = $this->admin();

        $this->change($user, [
            'current_password' => self::PASSWORD,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasErrors('password');
    }

    public function test_nobody_signed_out_can_reach_the_screen(): void
    {
        $this->get(route('adminpassword'))->assertRedirect(url('/admin'));

        $this->post(route('adminpassword'), [
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertRedirect(url('/admin'));
    }

    /**
     * A customer's session is not an admin's. The two portals keep separate
     * session keys precisely so that one cannot be mistaken for the other.
     */
    public function test_a_signed_in_customer_cannot_reach_the_admin_screen(): void
    {
        $party = new \App\Models\PartyModel;
        $party->party_type = 'customer';
        $party->name = 'Not An Admin';
        $party->mobile = '9000002001';
        $party->is_active = 1;
        $party->password = Hash::make(self::PASSWORD);
        $party->save();

        $this->withSession(['customer_id' => $party->id])
            ->get(route('adminpassword'))
            ->assertRedirect(url('/admin'));
    }

    /**
     * The route carries no id, so this asks that none is ever read from the
     * body either — an admin form must not be able to name another account.
     */
    public function test_changing_a_password_never_touches_another_account(): void
    {
        $mine = $this->admin();
        $theirs = $this->admin();

        $this->change($mine, [
            'id' => $theirs->id,
            'user_id' => $theirs->id,
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertRedirect('admin/dashboard');

        $this->assertTrue(Hash::check('a-brand-new-one', $mine->fresh()->password), 'mine changed');
        $this->assertTrue(Hash::check(self::PASSWORD, $theirs->fresh()->password), 'theirs did not');
    }

    // ------------------------------------------------------ every other device

    /*
     * A changed password is the owner's one remedy when it has got out, or when
     * somebody who had it has left. It is only a remedy if it reaches the
     * places that were already signed in with the old one: a phone that ticked
     * Remember me was otherwise good for another 400 days, and a browser that
     * was open kept going for as long as somebody kept clicking.
     */

    public function test_a_device_that_was_remembered_is_signed_out_by_the_change(): void
    {
        $user = $this->admin();

        // The phone: signed in with Remember me ticked, then put in a pocket.
        $phone = $this->signIn($user, self::PASSWORD, remember: true)->getCookie($this->recaller());
        $this->assertNotNull($phone, 'Remember me gave the phone its cookie');

        // The office PC changes the password.
        $this->anotherDevice();
        $this->signIn($user, self::PASSWORD);
        $this->post(route('adminpassword'), $this->newPassword())->assertRedirect('admin/dashboard');

        // The phone comes back with nothing but that cookie.
        $this->anotherDevice();
        $this->withCookie($this->recaller(), $phone->getValue())
            ->get('admin/dashboard')
            ->assertRedirect(url('/admin'));

        $this->assertGuest();

        /*
         * Nor by way of the sign-in page, which is where a phone's bookmark
         * most likely points. That page asks only whether anybody is signed
         * in. A cookie still naming the account would have signed the phone in
         * there, and the office pages, finding a session with no password
         * noted in it yet, would have taken the one there is now.
         */
        $this->anotherDevice();
        $this->withCookie($this->recaller(), $phone->getValue())
            ->get('/admin')
            ->assertOk();

        $this->assertGuest();

        $this->nextPage();
        $this->get('admin/dashboard')->assertRedirect(url('/admin'));
    }

    public function test_a_browser_left_open_elsewhere_is_signed_out_at_its_next_click(): void
    {
        $user = $this->admin();

        // The other browser: signed in and in use.
        $this->signIn($user, self::PASSWORD);
        $this->nextPage();
        $this->get('admin/dashboard')->assertOk();
        $elsewhere = $this->app['session']->all();

        // This one changes the password.
        $this->anotherDevice();
        $this->signIn($user, self::PASSWORD);
        $this->post(route('adminpassword'), $this->newPassword())->assertRedirect('admin/dashboard');

        // The other browser's next page goes back to the sign-in page, not to
        // an error page, and stays signed out after it.
        $this->anotherDevice();
        $this->withSession($elsewhere)->get('admin/dashboard')->assertRedirect(url('/admin'));
        $this->nextPage();
        $this->get('admin/dashboard')->assertRedirect(url('/admin'));

        // Where whoever is there can sign in again, with the new password.
        $this->signIn($user, 'a-brand-new-one')->assertRedirect('admin/dashboard');
        $this->nextPage();
        $this->get('admin/dashboard')->assertOk();
    }

    /**
     * The one device the change is made on stays signed in. Whoever changed it
     * has just proved they know both passwords; signing them out too would only
     * send them straight back to the sign-in page to type the new one.
     */
    public function test_the_device_that_made_the_change_stays_signed_in(): void
    {
        $user = $this->admin();

        $this->signIn($user, self::PASSWORD);

        $response = $this->post(route('adminpassword'), $this->newPassword())
            ->assertRedirect('admin/dashboard');

        $this->nextPage();
        $this->get('admin/dashboard')->assertOk();
        $this->nextPage();
        $this->get(route('adminpassword'))->assertOk();

        // And it is not remembered on the way: a browser that did not tick
        // Remember me is not given a 400-day cookie for changing a password.
        $this->assertNull($response->getCookie($this->recaller()));
    }

    /**
     * A device that ticked Remember me keeps it. Its old cookie carries the old
     * password and the old token, both of which the change retires, so it is
     * handed a new one, or it would be signed out with everybody else the next
     * time its session ran out.
     */
    public function test_the_device_that_made_the_change_is_still_remembered(): void
    {
        $user = $this->admin();

        $old = $this->signIn($user, self::PASSWORD, remember: true)->getCookie($this->recaller());

        /*
         * A browser sends its cookies back with every page; the test client
         * sends only what it is handed, and keeps the sign-in's cookie queued,
         * to come back on the next response whatever that request did.
         */
        $this->withCookie($this->recaller(), $old->getValue());
        $this->app['cookie']->flushQueuedCookies();

        $new = $this->post(route('adminpassword'), $this->newPassword())
            ->assertRedirect('admin/dashboard')
            ->getCookie($this->recaller());

        $this->assertNotNull($new, 'a fresh Remember me cookie');
        $this->assertNotSame($old->getValue(), $new->getValue());

        // The browser closed and opened again a day later: no session, only
        // the cookie.
        $this->anotherDevice();
        $this->withCookie($this->recaller(), $new->getValue())
            ->get('admin/dashboard')
            ->assertOk();
    }

    /**
     * Every office page, not only the dashboard the tests above go to. The
     * check is in the 'admin' middleware group, so this holds for a route
     * group added later as long as it says 'admin', and fails if it ever
     * comes out of the group.
     */
    public function test_every_office_page_checks_the_password_it_was_signed_in_with(): void
    {
        $router = $this->app['router'];

        $office = collect($router->getRoutes()->getRoutes())
            ->map(fn ($route) => [$route->uri(), $router->gatherRouteMiddleware($route)])
            ->filter(fn ($route) => in_array(AdminMiddleware::class, $route[1], true));

        // Not a check of nothing: the office side is most of the application.
        $this->assertGreaterThan(50, $office->count());

        foreach ($office as [$uri, $middleware]) {
            $this->assertContains(AuthenticateSession::class, $middleware, $uri);
        }
    }

    public function test_the_screen_asks_for_the_current_password(): void
    {
        $user = $this->admin();

        $body = $this->actingAs($user)->get(route('adminpassword'))->assertOk()->getContent();

        $this->assertStringContainsString('data-vue="vue-admin-password"', $body);

        $props = $this->actingAs($user)->getJson(route('adminpassword'))->assertOk()->json('props');

        $this->assertTrue($props['requireCurrent']);
        // The hash never leaves the server, on this screen least of all.
        $this->assertStringNotContainsString('$2y$', json_encode($props));
    }

    /** The component has to be registered, or the screen is a blank card. */
    public function test_the_component_is_registered(): void
    {
        $mounts = file_get_contents(resource_path('js/mounts.js'));

        $this->assertStringContainsString("'vue-admin-password'", $mounts);
        $this->assertStringContainsString("'vue-customer-password'", $mounts);
    }
}
