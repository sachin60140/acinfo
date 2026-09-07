<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileModel;
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

    // ------------------------------------------------------------- the files

    /**
     * A file with a work on it, and a vendor holding it at a known rate.
     *
     * The vendor is the point: every leak test below needs a real name and a
     * real figure on the row, because asserting that the word "vendor" is
     * absent proves nothing if no vendor was ever attached.
     */
    private function fileWithVendor(PartyModel $customer, array $overrides = []): \App\Models\WorkFileModel
    {
        $vendor = $this->party('vendor', $overrides['vendor_mobile'] ?? '9000009999', ['password' => null]);
        $vendor->name = 'Dabloo Ji Muzaffarpur';
        $vendor->save();

        $file = $this->fileFor($customer);
        $file->registration_no = $overrides['registration_no'] ?? 'BR06GG1408';
        $file->status = $overrides['status'] ?? 'file_dispatch';
        $file->customer_amount = 9000;
        $file->vendor_id = $vendor->id;
        // The number that is the margin when set against 9000.
        $file->vendor_amount = 5250;
        $file->vendor_date = '2026-01-20';
        $file->save();

        $item = new \App\Models\WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $file->work_type_id;
        $item->customer_amount = 9000;
        $item->vendor_amount = 5250;
        $item->status = $file->status;
        $item->approved_on = $overrides['approved_on'] ?? null;
        $item->save();

        return $file;
    }

    public function test_a_customer_sees_their_own_files_and_nobody_elses(): void
    {
        $mine = $this->party('customer', '9000000601');
        $theirs = $this->party('customer', '9000000602');

        $this->fileWithVendor($mine, ['registration_no' => 'BR06MINE01']);
        $this->fileWithVendor($theirs, ['registration_no' => 'BR06THEM01', 'vendor_mobile' => '9000009998']);

        $body = $this->withSession(['customer_id' => $mine->id])
            ->get(route('customer.files'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('BR06MINE01', $body);
        $this->assertStringNotContainsString('BR06THEM01', $body);
    }

    /**
     * The single most important test in this portal.
     *
     * vendor_amount sits on the same row as file_no. The gap between it and
     * customer_amount is what this business earns, and a query that fetched the
     * row and trusted the caller to drop four fields would put it on the
     * customer's screen with no error anywhere.
     */
    public function test_nothing_about_a_vendor_reaches_the_file_list(): void
    {
        $customer = $this->party('customer', '9000000603');

        $this->fileWithVendor($customer);

        $payload = json_encode($this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.files'))
            ->assertOk()
            ->json());

        $this->assertStringNotContainsString('vendor', strtolower($payload), 'no vendor field');
        $this->assertStringNotContainsString('Dabloo Ji', $payload, 'not who holds the papers');
        $this->assertStringNotContainsString('5250', $payload, 'not what they are paid');
        $this->assertStringNotContainsString('5,250', $payload);
        // And the margin, which is neither figure but follows from both.
        $this->assertStringNotContainsString('3750', $payload);

        // What the customer was charged is theirs to see, so the test is not
        // passing merely because the payload is empty.
        $this->assertStringContainsString('9000', $payload);
    }

    public function test_the_status_is_said_in_words_a_customer_uses(): void
    {
        $customer = $this->party('customer', '9000000604');

        $this->fileWithVendor($customer, ['status' => 'file_dispatch']);

        $body = $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.files'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Submitted at the RTO', $body);
        // Dispatch is the moment the papers go to a vendor. Naming it that way
        // would say a vendor exists even without naming which.
        $this->assertStringNotContainsString('File Dispatch', $body);
        $this->assertStringNotContainsString('file_dispatch', $body);
    }

    /**
     * Every state has to have a customer-facing word, or one day a file reaches
     * a state nobody translated and the office's own shorthand goes on screen.
     */
    public function test_every_status_the_database_allows_has_a_customer_word(): void
    {
        foreach (array_keys(\App\Models\WorkFileModel::STATUSES) as $status) {
            $this->assertArrayHasKey(
                $status,
                \App\Models\WorkFileModel::CUSTOMER_STATUSES,
                "$status has no customer-facing wording"
            );
        }
    }

    public function test_an_unmapped_status_never_shows_the_raw_key(): void
    {
        $this->assertSame('In progress', \App\Models\WorkFileModel::customerStatus('some_new_state'));
        $this->assertSame('In progress', \App\Models\WorkFileModel::customerStatus(null));
        // And an unknown state still gets a tone, so the badge is never unstyled.
        $this->assertSame('waiting', \App\Models\WorkFileModel::customerTone('some_new_state'));
    }

    /**
     * The colouring must not smuggle the vocabulary back in.
     *
     * The grid writes this key straight into a data-state attribute, so the
     * office's own words would be in the page source even though no reader ever
     * sees them rendered.
     */
    public function test_no_office_status_key_reaches_the_page_source(): void
    {
        $customer = $this->party('customer', '9000000611');

        foreach (array_keys(\App\Models\WorkFileModel::STATUSES) as $i => $status) {
            $this->fileWithVendor($customer, [
                'status' => $status,
                'vendor_mobile' => '90000098'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'registration_no' => 'BR06KEY'.$i,
            ]);
        }

        $body = $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.files'))->assertOk()->getContent();

        foreach (array_keys(\App\Models\WorkFileModel::STATUSES) as $status) {
            // "cancelled" is the same word in both vocabularies, so it is not
            // evidence of the office's leaking through.
            if ($status === 'cancelled') {
                continue;
            }

            $this->assertStringNotContainsString($status, $body, "the raw key $status is in the page");
        }
    }

    public function test_a_cancelled_file_is_shown_as_charging_nothing(): void
    {
        $customer = $this->party('customer', '9000000605');

        $this->fileWithVendor($customer, ['status' => 'cancelled', 'registration_no' => 'BR06CANX1']);

        $row = collect($this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.files'))->assertOk()->json('props.rows'))
            ->firstWhere('registration_no', 'BR06CANX1');

        $this->assertSame('Cancelled', $row['status']);
        // Cancelled charged nobody, so the list must not show the face figure
        // and disagree with the statement on the next page.
        $this->assertEquals(0, $row['charged']);
    }

    public function test_a_folder_whose_works_disagree_says_which_is_which(): void
    {
        $customer = $this->party('customer', '9000000606');

        $file = $this->fileWithVendor($customer, ['status' => 'partly_approved', 'registration_no' => 'BR06SPLT1']);

        // A second work on the same folder, already through.
        $done = new \App\Models\WorkFileItemModel;
        $done->work_file_id = $file->id;
        $done->work_type_id = $file->work_type_id;
        $done->customer_amount = 3000;
        $done->status = 'approval_done';
        $done->approved_on = '2026-02-14';
        $done->save();

        $row = collect($this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.files'))->assertOk()->json('props.rows'))
            ->firstWhere('registration_no', 'BR06SPLT1');

        $this->assertSame('Partly approved', $row['status']);
        $this->assertNotNull($row['works_note'], 'a split folder says how it is split');
        $this->assertStringContainsString('approved', $row['works_note']);
        $this->assertStringContainsString('pending', $row['works_note']);
        $this->assertStringContainsString('14-02-2026', $row['approved_on']);
    }

    public function test_a_folder_going_one_way_says_nothing_extra(): void
    {
        $customer = $this->party('customer', '9000000607');

        $this->fileWithVendor($customer, ['registration_no' => 'BR06ONEW1']);

        $row = collect($this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.files'))->assertOk()->json('props.rows'))
            ->firstWhere('registration_no', 'BR06ONEW1');

        // A second line repeating what the badge says is a line nobody reads.
        $this->assertNull($row['works_note']);
    }

    public function test_the_home_screen_counts_the_files_by_where_they_stand(): void
    {
        $customer = $this->party('customer', '9000000608');

        $this->fileWithVendor($customer, ['status' => 'in_office', 'registration_no' => 'BR06AAA01']);
        $this->fileWithVendor($customer, ['status' => 'approval_done', 'vendor_mobile' => '9000009997', 'registration_no' => 'BR06AAA02']);
        $this->fileWithVendor($customer, ['status' => 'approval_done', 'vendor_mobile' => '9000009996', 'registration_no' => 'BR06AAA03']);
        $this->fileWithVendor($customer, ['status' => 'paper_returned', 'vendor_mobile' => '9000009995', 'registration_no' => 'BR06AAA04']);

        $body = $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.dashboard'))
            ->assertOk()
            ->getContent();

        // One open, two approved, one returned — read off the rendered cards.
        $this->assertMatchesRegularExpression('/In Progress.*?>1</s', $body);
        $this->assertMatchesRegularExpression('/Approved.*?>2</s', $body);
        $this->assertMatchesRegularExpression('/Returned.*?>1</s', $body);
    }

    public function test_a_customer_with_no_files_is_told_so(): void
    {
        $customer = $this->party('customer', '9000000609');

        $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.dashboard'))
            ->assertOk()
            ->assertSee('Nothing yet');
    }

    public function test_the_file_list_offers_no_way_into_the_office(): void
    {
        $customer = $this->party('customer', '9000000610');

        $this->fileWithVendor($customer);

        $this->assertStringNotContainsString(
            'admin/',
            $this->withSession(['customer_id' => $customer->id])
                ->get(route('customer.files'))->assertOk()->getContent()
        );
    }

    // -------------------------------------------------------- one file, in full

    /**
     * A real image on disk, and the row that points at it.
     *
     * Written into the same directory the office uploads to, under a name these
     * tests own, and removed afterwards. Serving evidence cannot be tested
     * against evidence that does not exist.
     */
    private function withApproval(\App\Models\WorkFileModel $file, ?int $itemId = null): string
    {
        $directory = public_path(\App\Models\WorkFileModel::UPLOAD_DIR);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $name = 'phpunit-'.uniqid().'.png';
        // The shortest valid PNG: a 1x1 transparent pixel.
        file_put_contents($directory.'/'.$name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            .'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        ));

        $stored = \App\Models\WorkFileModel::UPLOAD_DIR.'/'.$name;
        $this->uploads[] = $directory.'/'.$name;

        if ($itemId === null) {
            $file->approval_screenshot = $stored;
            $file->save();
        } else {
            \App\Models\WorkFileItemModel::where('id', $itemId)->update(['approval_screenshot' => $stored]);
        }

        return $stored;
    }

    /** Files written to public/ by a test, which no transaction rolls back. */
    private array $uploads = [];

    protected function tearDown(): void
    {
        foreach ($this->uploads as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->uploads = [];

        parent::tearDown();
    }

    public function test_a_customer_can_open_their_own_file(): void
    {
        $customer = $this->party('customer', '9000000801');
        $file = $this->fileWithVendor($customer, ['vendor_mobile' => '9000009801', 'registration_no' => 'BR06OPEN1']);

        $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.file', $file->id))
            ->assertOk()
            ->assertSee('BR06OPEN1')
            ->assertSee($file->file_no);
    }

    /**
     * The one an attacker would try, and the one a bored customer would try
     * too: their own file id, plus one.
     */
    public function test_another_customers_file_is_not_found(): void
    {
        $mine = $this->party('customer', '9000000802');
        $theirs = $this->party('customer', '9000000803');

        $file = $this->fileWithVendor($theirs, ['vendor_mobile' => '9000009802', 'registration_no' => 'BR06THRS1']);

        $this->withSession(['customer_id' => $mine->id])
            ->get(route('customer.file', $file->id))
            ->assertNotFound();
    }

    public function test_a_file_cannot_be_opened_without_signing_in(): void
    {
        $customer = $this->party('customer', '9000000804');
        $file = $this->fileWithVendor($customer, ['vendor_mobile' => '9000009803']);

        $this->get(route('customer.file', $file->id))
            ->assertRedirect(route('customer.login'));
    }

    public function test_the_file_page_says_nothing_about_a_vendor(): void
    {
        $customer = $this->party('customer', '9000000805');
        $file = $this->fileWithVendor($customer, ['vendor_mobile' => '9000009804']);

        $payload = json_encode($this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.file', $file->id))->assertOk()->json());

        $this->assertStringNotContainsString('vendor', strtolower($payload));
        $this->assertStringNotContainsString('Dabloo Ji', $payload);
        $this->assertStringNotContainsString('5250', $payload);
        $this->assertStringContainsString('9000', $payload, 'what they were charged is theirs');
    }

    public function test_each_work_carries_its_own_status_and_approval_date(): void
    {
        $customer = $this->party('customer', '9000000806');
        $file = $this->fileWithVendor($customer, [
            'vendor_mobile' => '9000009805',
            'status' => 'partly_approved',
        ]);

        $done = new \App\Models\WorkFileItemModel;
        $done->work_file_id = $file->id;
        $done->work_type_id = $file->work_type_id;
        $done->customer_amount = 3000;
        $done->status = 'approval_done';
        $done->approved_on = '2026-03-09';
        $done->save();

        $rows = $this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.file', $file->id))->assertOk()->json('props.rows');

        $this->assertCount(2, $rows);

        $approved = collect($rows)->firstWhere('approved_on', '09-03-2026');

        $this->assertNotNull($approved, 'the approved work names the day it came through');
        $this->assertSame('Approved', $approved['status']);

        // And the one still in hand is not called approved.
        $this->assertSame(
            1,
            collect($rows)->where('status', 'Approved')->count(),
            'only the work that is through says so'
        );
    }

    // ------------------------------------------------------- the approval image

    public function test_a_customer_can_see_the_approval_on_their_own_work(): void
    {
        $customer = $this->party('customer', '9000000807');
        $file = $this->fileWithVendor($customer, ['vendor_mobile' => '9000009806', 'status' => 'approval_done']);

        $item = \App\Models\WorkFileItemModel::where('work_file_id', $file->id)->first();
        $this->withApproval($file, $item->id);

        $response = $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.file.approval', ['id' => $file->id, 'item' => $item->id]))
            ->assertOk();

        $this->assertSame('image/png', $response->headers->get('content-type'));
        // One customer's document behind a session. A shared cache holding it
        // would hand it to the next person through the same proxy.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
    }

    /**
     * The reason this route exists at all.
     *
     * These images sit under public/ and are web-served with no authentication.
     * Linking them directly would turn an unguessable filename into a URL that
     * is forwarded, saved and shared for good; behind the route, the ownership
     * is checked on every read.
     */
    public function test_another_customers_approval_is_not_served(): void
    {
        $mine = $this->party('customer', '9000000808');
        $theirs = $this->party('customer', '9000000809');

        $file = $this->fileWithVendor($theirs, ['vendor_mobile' => '9000009807', 'status' => 'approval_done']);
        $item = \App\Models\WorkFileItemModel::where('work_file_id', $file->id)->first();
        $this->withApproval($file, $item->id);

        $this->withSession(['customer_id' => $mine->id])
            ->get(route('customer.file.approval', ['id' => $file->id, 'item' => $item->id]))
            ->assertNotFound();

        /*
         * And to nobody at all without a session. Flushed first: the test
         * client keeps the session between requests within one test, so
         * without this the "signed out" request is still signed in as $mine and
         * the 404 above would be all this proved.
         */
        $this->flushSession();

        $this->get(route('customer.file.approval', ['id' => $file->id, 'item' => $item->id]))
            ->assertRedirect(route('customer.login'));
    }

    /**
     * An item id from a file that is not this one must not resolve, or the file
     * check is decoration and the item id is the real address.
     */
    public function test_an_item_from_another_file_is_not_served(): void
    {
        $mine = $this->party('customer', '9000000810');
        $theirs = $this->party('customer', '9000000811');

        $ours = $this->fileWithVendor($mine, ['vendor_mobile' => '9000009808']);

        $other = $this->fileWithVendor($theirs, ['vendor_mobile' => '9000009809', 'status' => 'approval_done']);
        $otherItem = \App\Models\WorkFileItemModel::where('work_file_id', $other->id)->first();
        $this->withApproval($other, $otherItem->id);

        // Our file, their item.
        $this->withSession(['customer_id' => $mine->id])
            ->get(route('customer.file.approval', ['id' => $ours->id, 'item' => $otherItem->id]))
            ->assertNotFound();
    }

    /**
     * The link has to be the route, not the file.
     *
     * Everything else here guards the route; this guards that the route is what
     * the customer is actually given. A link straight to /uploads/approvals/…
     * would be served by the web server with no session, no ownership check and
     * no expiry, and would keep working for whoever it was forwarded to.
     */
    public function test_the_approval_is_linked_through_the_application(): void
    {
        $customer = $this->party('customer', '9000000814');
        $file = $this->fileWithVendor($customer, ['vendor_mobile' => '9000009812', 'status' => 'approval_done']);

        $item = \App\Models\WorkFileItemModel::where('work_file_id', $file->id)->first();
        $stored = $this->withApproval($file, $item->id);

        $body = $this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.file', $file->id))->assertOk();

        $this->assertSame(
            route('customer.file.approval', ['id' => $file->id, 'item' => $item->id]),
            $body->json('props.rows.0.screenshot_url'),
            'the approval is offered through the route'
        );

        /*
         * The stored path itself never appears, in any form. Encoded with
         * unescaped slashes because json_encode writes "\/" by default, and a
         * needle containing "/" would then miss a payload that does carry it.
         */
        $payload = json_encode($body->json(), JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString($stored, $payload);
        $this->assertStringNotContainsString(WorkFileModel::UPLOAD_DIR, $payload);
    }

    public function test_a_work_with_no_approval_offers_no_link(): void
    {
        $customer = $this->party('customer', '9000000812');
        $file = $this->fileWithVendor($customer, ['vendor_mobile' => '9000009810']);

        $rows = $this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.file', $file->id))->assertOk()->json('props.rows');

        $this->assertNull($rows[0]['screenshot_url']);
        $this->assertNull($rows[0]['screenshot']);

        // And asking for it anyway gets nothing.
        $item = \App\Models\WorkFileItemModel::where('work_file_id', $file->id)->first();

        $this->withSession(['customer_id' => $customer->id])
            ->get(route('customer.file.approval', ['id' => $file->id, 'item' => $item->id]))
            ->assertNotFound();
    }

    /**
     * A path that is not one of ours is never read from disk.
     *
     * These values come from our own rows today, so this is not guarding a
     * hostile input — it guards the day something else writes that column.
     */
    public function test_a_path_outside_the_upload_directory_is_refused(): void
    {
        $this->assertFalse(WorkFileModel::isStoredUpload('../../.env'));
        $this->assertFalse(WorkFileModel::isStoredUpload('.env'));

        /*
         * The one that actually escapes.
         *
         * Three levels up from public/uploads/approvals is the project root,
         * where .env really is — so this path passes the prefix check and
         * is_file() finds a file. Two levels only reaches public/, where
         * nothing is, which is why the shallower version proved nothing and
         * let the "no .." rule look redundant when it was planted broken.
         */
        $escape = 'uploads/approvals/../../../.env';

        $this->assertTrue(
            is_file(public_path($escape)),
            'this test is only meaningful while that path resolves to a real file'
        );
        $this->assertFalse(WorkFileModel::isStoredUpload($escape));
        $this->assertFalse(WorkFileModel::isStoredUpload(null));
        $this->assertFalse(WorkFileModel::isStoredUpload(''));
        // And one that does not exist, however well-named.
        $this->assertFalse(WorkFileModel::isStoredUpload('uploads/approvals/not-here.png'));
    }

    public function test_the_files_list_leads_to_the_file(): void
    {
        $customer = $this->party('customer', '9000000813');
        $file = $this->fileWithVendor($customer, ['vendor_mobile' => '9000009811']);

        $row = collect($this->withSession(['customer_id' => $customer->id])
            ->getJson(route('customer.files'))->assertOk()->json('props.rows'))
            ->firstWhere('file_no', $file->file_no);

        $this->assertSame(route('customer.file', $file->id), $row['view_url']);
    }

    /**
     * The seam between Blade and Vue, for the two portal screens.
     *
     * ScreenPropsTest guards this for every office screen, but it signs in as an
     * admin and walks a fixed list of admin URLs — it cannot reach a page behind
     * customerAuth. Without something here, a portal screen whose props and
     * component disagree would render as a blank white card: no server error, no
     * failing test, and the only person who sees it is the customer.
     */
    public static function portalScreens(): array
    {
        return [
            'files' => ['customer.files', 'vue-customer-files'],
            'statement' => ['customer.statement', 'vue-customer-statement'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('portalScreens')]
    public function test_a_portal_screen_serves_the_same_component_either_way(string $route, string $mount): void
    {
        $customer = $this->party('customer', '9000000701');
        $this->fileWithVendor($customer, ['vendor_mobile' => '9000009901']);
        $this->entry($customer->id, '2026-01-05', 'debit', 4000);

        $json = $this->withSession(['customer_id' => $customer->id])
            ->getJson(route($route))
            ->assertOk()
            ->json();

        $this->assertSame($mount, $json['mount'], 'the JSON names a different component');
        $this->assertNotEmpty($json['props']['columns'] ?? [], 'the component is handed no columns');

        // The page has to mount the same thing, or the two representations of
        // one screen have drifted.
        $page = $this->withSession(['customer_id' => $customer->id])
            ->get(route($route))->assertOk()->getContent();

        $this->assertStringContainsString('data-vue="'.$mount.'"', $page);

        // And the component has to be registered, or the mount point stays an
        // empty div and the screen is blank.
        $this->assertStringContainsString(
            "'".$mount."'",
            file_get_contents(resource_path('js/mounts.js')),
            "$mount is not registered in mounts.js"
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
