<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
