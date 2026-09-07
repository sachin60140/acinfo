<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The customer portal's front door.
 *
 * Phase 1 is the way in and nothing behind it, so everything here is about who
 * gets through and who does not. That is worth more coverage than it looks:
 * the failure mode of a login is silent, and the failure mode of a shared
 * session key is a customer reading somebody else's ledger with no error
 * anywhere.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class CustomerPortalTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'a-real-password-8';

    private function party(string $type, string $mobile, array $attributes = []): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.$mobile;
        $party->mobile = $mobile;
        $party->is_active = $attributes['is_active'] ?? 1;
        $party->password = array_key_exists('password', $attributes)
            ? $attributes['password']
            : Hash::make(self::PASSWORD);
        $party->save();

        return $party;
    }

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Portal Test Admin';
        $user->email = 'portal-admin-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    private function signIn(string $mobile, string $password)
    {
        return $this->post(route('customer.authenticate'), [
            'mobile' => $mobile,
            'password' => $password,
        ]);
    }

    // ---------------------------------------------------------------- the gate

    /**
     * The two screens Phase 1 adds that nothing else renders.
     *
     * A Blade page with a typo in it is a 500 at the moment somebody tries to
     * sign in, and the tests that post straight to the login route would never
     * touch it.
     */
    public function test_the_login_page_renders_a_form_that_works_without_javascript(): void
    {
        $body = $this->get(route('customer.login'))
            ->assertOk()
            ->assertSee('Customer Login')
            ->getContent();

        $this->assertStringContainsString(route('customer.authenticate'), $body, 'the form posts somewhere');
        $this->assertStringContainsString('_token', $body, 'and carries a CSRF token');
        // A customer's own account has no business in a search index.
        $this->assertStringContainsString('noindex', $body);
    }

    public function test_the_password_screen_renders_for_a_customer(): void
    {
        $customer = $this->party('customer', '9000000407', ['password' => null]);

        $this->actingAs($this->admin())
            ->get(route('party.password', $customer->id))
            ->assertOk()
            ->assertSee($customer->name);
    }

    /** Signed in already, so the login page sends them on rather than asking again. */
    public function test_the_login_page_steps_aside_for_someone_already_signed_in(): void
    {
        $customer = $this->party('customer', '9000000408');

        $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.login'))
            ->assertRedirect(route('customer.dashboard'));
    }

    public function test_the_portal_is_shut_to_a_visitor_with_no_session(): void
    {
        $this->get(route('customer.dashboard'))
            ->assertRedirect(route('customer.login'));
    }

    /**
     * The reason this portal has its own session key at all.
     *
     * A signed-in client carries 'userid'. If the customer pages gated on that,
     * or shared it, a client would walk into the customer portal and be
     * resolved as whichever party happens to have the same id — a different
     * person, with a different ledger, and no error to show for it.
     */
    public function test_a_signed_in_client_is_not_a_signed_in_customer(): void
    {
        $customer = $this->party('customer', '9000000201');

        $this->withSession(['userid' => $customer->id, 'username' => 'A Client'])
            ->get(route('customer.dashboard'))
            ->assertRedirect(route('customer.login'));
    }

    /** And the same fact from the other side. */
    public function test_a_signed_in_customer_is_not_a_signed_in_client(): void
    {
        $customer = $this->party('customer', '9000000202');

        $this->withSession(['customer_id' => $customer->id])
            ->get(route('userdashboard'))
            ->assertRedirect('/user');
    }

    // ------------------------------------------------------------- signing in

    public function test_a_customer_with_a_password_gets_in(): void
    {
        $customer = $this->party('customer', '9000000203');

        $this->signIn('9000000203', self::PASSWORD)
            ->assertRedirect(route('customer.dashboard'));

        $this->assertSame($customer->id, session('customer_id'));

        $this->get(route('customer.dashboard'))
            ->assertOk()
            ->assertSee($customer->name);
    }

    public function test_signing_in_stamps_the_visit_without_editing_the_record(): void
    {
        $customer = $this->party('customer', '9000000204');

        /*
         * Aged deliberately. updated_at has second precision, so a record
         * created and signed into within the same second compares equal however
         * the login writes it — the assertion below would hold even against a
         * plain save(), and would be proving nothing at all.
         */
        $customer->forceFill(['updated_at' => now()->subDay()])->saveQuietly();

        $before = $customer->fresh()->updated_at;

        $this->assertNull($customer->last_login_at);

        $this->signIn('9000000204', self::PASSWORD);

        $after = $customer->fresh();

        $this->assertNotNull($after->last_login_at, 'the visit is recorded');
        // A visit is not an edit. The ledger screens sort on updated_at.
        $this->assertEquals($before, $after->updated_at, 'the record itself is untouched');
    }

    public function test_signing_out_lets_go_of_the_session(): void
    {
        $customer = $this->party('customer', '9000000205');

        $this->signIn('9000000205', self::PASSWORD);
        $this->assertSame($customer->id, session('customer_id'));

        $this->post(route('customer.logout'))->assertRedirect(route('customer.login'));

        $this->assertNull(session('customer_id'));

        $this->get(route('customer.dashboard'))->assertRedirect(route('customer.login'));
    }

    // ------------------------------------------------------- who is refused

    /**
     * Every refusal says the same thing.
     *
     * Told apart, this form answers "is this number one of your customers?" for
     * anyone who cares to ask — which is worth more to someone fishing than the
     * password itself.
     */
    public static function refusals(): array
    {
        return [
            'a number that is not ours' => ['9000000999', self::PASSWORD],
            'the wrong password' => ['9000000301', 'not-the-password'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_every_refusal_reads_the_same(string $mobile, string $password): void
    {
        $this->party('customer', '9000000301');

        $this->signIn($mobile, $password)
            ->assertRedirect('/')
            ->assertSessionHas('error', 'Mobile number or password is incorrect.');

        $this->assertNull(session('customer_id'));
    }

    public function test_a_customer_who_was_never_given_a_login_cannot_sign_in(): void
    {
        $this->party('customer', '9000000302', ['password' => null]);

        // Any password at all, because there is nothing to check it against.
        $this->signIn('9000000302', self::PASSWORD)
            ->assertSessionHas('error', 'Mobile number or password is incorrect.');

        $this->assertNull(session('customer_id'));
    }

    public function test_a_vendor_cannot_use_the_customer_portal(): void
    {
        // A password set on a vendor by accident must still not be a way in.
        $this->party('vendor', '9000000303');

        $this->signIn('9000000303', self::PASSWORD)
            ->assertSessionHas('error', 'Mobile number or password is incorrect.');

        $this->assertNull(session('customer_id'));
    }

    public function test_a_deactivated_customer_cannot_sign_in(): void
    {
        $this->party('customer', '9000000304', ['is_active' => 0]);

        $this->signIn('9000000304', self::PASSWORD)
            ->assertSessionHas('error', 'Mobile number or password is incorrect.');

        $this->assertNull(session('customer_id'));
    }

    /**
     * Deactivated while signed in, not at the next login.
     *
     * The session outlives the change, so the gate alone would let someone keep
     * reading an account the office has closed until they happen to sign out.
     */
    public function test_a_customer_deactivated_mid_session_is_stopped_at_the_next_page(): void
    {
        $customer = $this->party('customer', '9000000305');

        $this->signIn('9000000305', self::PASSWORD);
        $this->get(route('customer.dashboard'))->assertOk();

        $customer->is_active = 0;
        $customer->save();

        $this->get(route('customer.dashboard'))->assertNotFound();
    }

    // ------------------------------------------------------ issuing a password

    public function test_the_office_can_give_a_customer_a_login(): void
    {
        $customer = $this->party('customer', '9000000401', ['password' => null]);

        $this->actingAs($this->admin())
            ->post(route('party.password', $customer->id), [
                'password' => 'issued-by-the-office',
                'password_confirmation' => 'issued-by-the-office',
            ])
            ->assertRedirect(route('party.index', 'customer'));

        $stored = $customer->fresh()->password;

        $this->assertNotSame('issued-by-the-office', $stored, 'stored as a hash, never as typed');
        $this->assertTrue(Hash::check('issued-by-the-office', $stored));

        // And it works.
        $this->signIn('9000000401', 'issued-by-the-office')
            ->assertRedirect(route('customer.dashboard'));
    }

    public function test_a_vendor_is_not_offered_a_login(): void
    {
        $vendor = $this->party('vendor', '9000000402', ['password' => null]);

        $this->actingAs($this->admin())
            ->get(route('party.password', $vendor->id))
            ->assertNotFound();
    }

    public function test_only_the_customer_list_carries_the_login_column(): void
    {
        $this->party('customer', '9000000403');

        $admin = $this->admin();

        $customers = $this->actingAs($admin)
            ->getJson(route('party.index', 'customer'))
            ->assertOk()
            ->json('props.columns');

        $vendors = $this->actingAs($admin)
            ->getJson(route('party.index', 'vendor'))
            ->assertOk()
            ->json('props.columns');

        $this->assertContains('login_action', array_column($customers, 'key'));
        $this->assertNotContains('login_action', array_column($vendors, 'key'));
    }

    /**
     * The list says whether a login exists. It must not say what it is.
     *
     * withBalance() reads the party table, and the hash sits on the same row as
     * the name and the mobile number that screen exists to show.
     */
    public function test_the_hash_never_reaches_the_customer_list(): void
    {
        $customer = $this->party('customer', '9000000404');

        $body = $this->actingAs($this->admin())
            ->getJson(route('party.index', 'customer'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($customer->password, $body);
        $this->assertStringNotContainsString('$2y$', $body, 'no bcrypt hash of any kind');
    }

    public function test_the_list_says_whether_each_customer_can_sign_in(): void
    {
        $withLogin = $this->party('customer', '9000000405');
        $without = $this->party('customer', '9000000406', ['password' => null]);

        $rows = collect($this->actingAs($this->admin())
            ->getJson(route('party.index', 'customer'))
            ->assertOk()
            ->json('props.rows'))
            ->keyBy('id');

        $this->assertSame('Change', $rows[$withLogin->id]['login_action']);
        $this->assertSame('Set', $rows[$without->id]['login_action']);
    }
}
