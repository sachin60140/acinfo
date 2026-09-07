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

    // --------------------------------------------------------- the statement

    private function entry(int $partyId, string $date, string $side, float $amount, string $particular = 'Test entry', ?int $workFileId = null): void
    {
        $entry = new \App\Models\PartyLedgerModel;
        $entry->party_id = $partyId;
        $entry->txn_date = $date;
        $entry->entry_type = $side;
        $entry->amount = $amount;
        $entry->particular = $particular;
        $entry->work_file_id = $workFileId;
        $entry->file_role = $workFileId ? 'customer' : null;
        $entry->ref_no = $workFileId ? 'F-TEST' : null;
        $entry->save();
    }

    /**
     * A bare work file, so a ledger entry can be tied to one.
     *
     * Deliberately not built through the receive screen: these tests are about
     * the portal, and all they need from a file is that work_file_id points at
     * a row which exists.
     */
    private function fileFor(PartyModel $customer): \App\Models\WorkFileModel
    {
        $file = new \App\Models\WorkFileModel;
        $file->file_no = 'F-'.substr((string) microtime(true), -6);
        $file->received_date = '2026-01-05';
        $file->work_type_id = \App\Models\WorkTypeModel::query()->value('id');
        $file->customer_id = $customer->id;
        $file->customer_amount = 4000;
        $file->status = 'in_office';
        $file->save();

        return $file;
    }

    /**
     * The whole security model of this portal in one test.
     *
     * There is nothing else keeping one customer out of another's ledger: the
     * statement route carries no id, and the party is the session's. If that
     * ever stops being true, this is what says so.
     */
    public function test_the_statement_is_the_signed_in_customer_and_nobody_else(): void
    {
        $mine = $this->party('customer', '9000000501');
        $theirs = $this->party('customer', '9000000502');

        $this->entry($mine->id, '2026-01-10', 'debit', 5000, 'My own work');
        $this->entry($theirs->id, '2026-01-11', 'debit', 9999, 'Somebody else entirely');

        $body = $this->withSession(['customer_id' => $mine->id])
            ->get(route('customer.statement'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('My own work', $body);
        $this->assertStringNotContainsString('Somebody else entirely', $body);
        $this->assertStringNotContainsString('9,999', $body);
    }

    /**
     * The first thing anybody would try.
     *
     * The route carries no id, so there is nothing to tamper with — but "there
     * is nothing to tamper with" is a property of today's code, and the way it
     * stops being true is somebody adding a filter that reads the query string.
     * Asking for another customer's id has to keep returning your own account
     * rather than theirs.
     */
    public function test_asking_for_another_customers_id_still_returns_your_own(): void
    {
        $mine = $this->party('customer', '9000000510');
        $theirs = $this->party('customer', '9000000511');

        $this->entry($mine->id, '2026-01-10', 'debit', 1234, 'My own work');
        $this->entry($theirs->id, '2026-01-11', 'debit', 8765, 'Somebody else entirely');

        foreach (['id', 'party_id', 'customer_id'] as $name) {
            $body = $this->withSession(['customer_id' => $mine->id])
                ->get(route('customer.statement', [$name => $theirs->id]))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('My own work', $body, "?$name returned the wrong account");
            $this->assertStringNotContainsString('Somebody else entirely', $body, "?$name leaked another customer");
        }
    }

    /**
     * The figures are the office's figures.
     *
     * A portal that computes its own totals is a portal that will one day
     * disagree with the statement the office prints, and the customer will be
     * holding the one that is wrong.
     */
    public function test_the_statement_agrees_with_the_one_the_office_prints(): void
    {
        $customer = $this->party('customer', '9000000503');

        $this->entry($customer->id, '2026-01-05', 'debit', 12000);
        $this->entry($customer->id, '2026-02-05', 'credit', 4500);
        $this->entry($customer->id, '2026-03-05', 'debit', 800);

        $office = $this->actingAs($this->admin())
            ->getJson(route('party.statement', $customer->id))
            ->assertOk()
            ->json('page');

        $portal = $this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.statement'))
            ->assertOk()
            ->json('page');

        foreach (['opening', 'debits', 'credits', 'closing', 'entryCount'] as $figure) {
            $this->assertSame($office[$figure], $portal[$figure], "$figure disagrees with the office");
        }
    }

    public function test_the_balance_is_said_in_words_and_never_with_a_minus_sign(): void
    {
        $owing = $this->party('customer', '9000000504');
        $inCredit = $this->party('customer', '9000000505');

        $this->entry($owing->id, '2026-01-05', 'debit', 7500);
        $this->entry($inCredit->id, '2026-01-05', 'credit', 2500);

        $owed = $this->withSession(['customer_id' => $owing->id])
            ->get(route('customer.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('You owe', $owed);
        $this->assertStringContainsString('7,500.00', $owed);

        $held = $this->withSession(['customer_id' => $inCredit->id])
            ->get(route('customer.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('In your credit', $held);
        $this->assertStringContainsString('2,500.00', $held);
        $this->assertStringNotContainsString('-2,500.00', $held, 'a balance never carries a minus sign');
    }

    public function test_a_settled_account_takes_neither_side(): void
    {
        $customer = $this->party('customer', '9000000506');

        $this->entry($customer->id, '2026-01-05', 'debit', 3000);
        $this->entry($customer->id, '2026-01-06', 'credit', 3000);

        $body = $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Account settled', $body);
        $this->assertStringNotContainsString('You owe', $body);
    }

    public function test_the_statement_narrows_to_a_period(): void
    {
        $customer = $this->party('customer', '9000000507');

        $this->entry($customer->id, '2026-01-10', 'debit', 1000, 'January work');
        $this->entry($customer->id, '2026-06-10', 'debit', 2000, 'June work');

        $body = $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.statement', ['from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('June work', $body);
        $this->assertStringNotContainsString('January work', $body);
    }

    public function test_a_backwards_period_is_refused(): void
    {
        $customer = $this->party('customer', '9000000508');

        $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.statement', ['from' => '2026-06-30', 'to' => '2026-06-01']))
            ->assertSessionHasErrors('to');
    }

    /**
     * What the office statement carries that this one must not.
     *
     * Its Ref No. column links into the file editor — a screen showing which
     * vendor holds the papers and what they are being paid. The margin is the
     * difference between that and what the customer was charged.
     */
    public function test_the_statement_offers_no_way_into_the_office(): void
    {
        $customer = $this->party('customer', '9000000509');

        // Tied to a file, which is what gives the office statement something to
        // link to. An entry typed straight into the ledger has no reference at
        // all, so a fixture without a file would leave the link untested.
        $file = $this->fileFor($customer);
        $this->entry($customer->id, '2026-01-05', 'debit', 4000, 'Work on a file', $file->id);

        $this->assertStringNotContainsString(
            'admin/',
            $this->withSession(['customer_id' => $customer->id])
                ->get(route('customer.statement'))->assertOk()->getContent(),
            'no link into the office'
        );

        /*
         * The word is checked against the data, not the page. The template's
         * stylesheets live under assets/vendor/, so a page-wide search for
         * "vendor" matches Bootstrap and says nothing about what leaked — it
         * fails whatever the code does, which is worse than not testing it.
         */
        $payload = $this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.statement'))
            ->assertOk()
            ->json();

        $this->assertStringNotContainsString(
            'vendor',
            strtolower(json_encode($payload)),
            'nothing about a vendor reaches the customer'
        );
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
