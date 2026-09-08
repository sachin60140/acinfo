<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The office statement, and what it carries off the screen.
 *
 * The opening balance was drawn in a card above the table, so no export ever
 * saw it: a printed statement began at its first transaction with a Balance
 * column counting up from a figure that appeared nowhere on the page. Handing
 * somebody a statement whose running total starts unexplained is the one thing
 * the document exists not to do.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class StatementExportTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Statement Admin';
        $user->email = 'statement-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    private function party(string $type, string $mobile): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.$mobile;
        $party->mobile = $mobile;
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function entry(int $partyId, string $date, string $side, float $amount, string $particular = 'Test entry', ?int $fileId = null): void
    {
        $entry = new PartyLedgerModel;
        $entry->party_id = $partyId;
        $entry->txn_date = $date;
        $entry->entry_type = $side;
        $entry->amount = $amount;
        $entry->particular = $particular;
        $entry->work_file_id = $fileId;
        $entry->file_role = $fileId ? 'customer' : null;
        $entry->save();
    }

    private function fileFor(PartyModel $customer): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-'.substr((string) microtime(true), -6);
        $file->received_date = '2026-01-05';
        $file->work_type_id = WorkTypeModel::query()->value('id');
        $file->customer_id = $customer->id;
        $file->customer_amount = 4000;
        $file->status = 'in_office';
        $file->save();

        return $file;
    }

    private function statement(PartyModel $party, array $query = [])
    {
        $url = route('party.statement', $party->id)
            .($query ? '?'.http_build_query($query) : '');

        return $this->actingAs($this->admin())->getJson($url)->assertOk();
    }

    // ------------------------------------------------------ opening & closing

    public function test_the_statement_carries_its_opening_and_closing_balance(): void
    {
        $party = $this->party('customer', '9100000101');

        $this->entry($party->id, '2026-01-10', 'debit', 4000, 'Older work');
        $this->entry($party->id, '2026-06-10', 'debit', 2500, 'June work');
        $this->entry($party->id, '2026-06-20', 'credit', 1000, 'Part payment');

        $page = $this->statement($party, ['from' => '2026-06-01', 'to' => '2026-06-30']);

        $lead = $page->json('props.lead');
        $tail = $page->json('props.tail');

        $this->assertCount(1, $lead);
        $this->assertSame('Opening Balance', $lead[0]['particular']);
        // What the period was entered with, and nothing from inside it.
        $this->assertEquals(4000, $lead[0]['balance']);

        $this->assertCount(1, $tail);
        $this->assertSame('Closing Balance', $tail[0]['particular']);
        $this->assertEquals(5500, $tail[0]['balance'], '4000 + 2500 - 1000');
        $this->assertEquals(2500, $tail[0]['debit']);
        $this->assertEquals(1000, $tail[0]['credit']);
    }

    public function test_the_framing_rows_agree_with_the_summary_above_the_table(): void
    {
        $party = $this->party('customer', '9100000102');

        $this->entry($party->id, '2026-01-10', 'debit', 7000);
        $this->entry($party->id, '2026-02-10', 'credit', 2000);

        $page = $this->statement($party);

        $this->assertEquals($page->json('page.opening'), $page->json('props.lead.0.balance'));
        $this->assertEquals($page->json('page.closing'), $page->json('props.tail.0.balance'));
        $this->assertEquals($page->json('page.debits'), $page->json('props.tail.0.debit'));
        $this->assertEquals($page->json('page.credits'), $page->json('props.tail.0.credit'));
    }

    /**
     * A balance carried into a period is not a transaction in it. Counted as
     * one, the Debit and Credit totals under the table would double.
     */
    public function test_the_framing_rows_are_not_entries(): void
    {
        $party = $this->party('customer', '9100000103');

        $this->entry($party->id, '2026-02-10', 'debit', 3000);

        $page = $this->statement($party);

        $this->assertCount(1, $page->json('props.rows'), 'one transaction, not three');

        foreach ($page->json('props.rows') as $row) {
            $this->assertNotSame('Opening Balance', $row['particular']);
            $this->assertNotSame('Closing Balance', $row['particular']);
        }
    }

    public function test_a_period_with_no_entries_still_says_what_was_brought_forward(): void
    {
        $party = $this->party('customer', '9100000104');

        $this->entry($party->id, '2026-01-10', 'debit', 3300, 'Before the period');

        $page = $this->statement($party, ['from' => '2026-06-01', 'to' => '2026-06-30']);

        $this->assertSame([], $page->json('props.rows'));
        $this->assertEquals(3300, $page->json('props.lead.0.balance'));
        $this->assertEquals(3300, $page->json('props.tail.0.balance'));
    }

    /**
     * The lead row has to have the shape of a row. A framing row missing a key
     * the columns name renders as a blank cell on screen and shifts every cell
     * after it in a CSV, which is how a statement column silently slips.
     */
    public function test_the_framing_rows_have_a_value_for_every_column(): void
    {
        $party = $this->party('customer', '9100000105');

        $this->entry($party->id, '2026-02-10', 'debit', 3000);

        $page = $this->statement($party);

        $keys = array_column($page->json('props.columns'), 'key');

        foreach (['lead', 'tail'] as $edge) {
            foreach ($page->json('props.'.$edge) as $row) {
                foreach ($keys as $key) {
                    $this->assertArrayHasKey($key, $row, "the $edge row has no $key");
                }
            }
        }
    }

    // --------------------------------------------------------------- remarks

    public function test_a_line_carries_the_remark_from_the_file_it_came_from(): void
    {
        $party = $this->party('customer', '9100000106');

        $file = $this->fileFor($party);
        $file->remarks = 'Original RC collected';
        $file->save();

        $this->entry($party->id, '2026-03-01', 'debit', 5000, 'Work on a file', $file->id);

        $page = $this->statement($party);

        $this->assertSame('Original RC collected', $page->json('props.rows.0.remarks'));
        $this->assertContains('remarks', array_column($page->json('props.columns'), 'key'));
    }

    /** "If available" — an account with none anywhere gets no column at all. */
    public function test_a_statement_with_no_remarks_carries_no_remarks_column(): void
    {
        $party = $this->party('customer', '9100000107');

        $this->entry($party->id, '2026-03-01', 'debit', 5000, 'Typed straight into the ledger');

        $page = $this->statement($party);

        $this->assertNotContains('remarks', array_column($page->json('props.columns'), 'key'));
    }

    public function test_an_entry_with_no_file_carries_no_remark(): void
    {
        $party = $this->party('customer', '9100000108');

        $file = $this->fileFor($party);
        $file->remarks = 'A note on the file';
        $file->save();

        $this->entry($party->id, '2026-03-01', 'debit', 5000, 'From a file', $file->id);
        $this->entry($party->id, '2026-03-02', 'credit', 1000, 'A payment received');

        $rows = collect($this->statement($party)->json('props.rows'));

        $this->assertSame('A note on the file', $rows->firstWhere('particular', 'From a file')['remarks']);
        // A payment is about no file, so it borrows no file's note.
        $this->assertNull($rows->firstWhere('particular', 'A payment received')['remarks']);
    }

    /**
     * One query for the page, not one per line. A statement can run to hundreds
     * of entries and this is the kind of loop that is only noticed on the
     * biggest account, which is the one most likely to be printed.
     */
    public function test_the_remarks_are_fetched_in_one_query(): void
    {
        $party = $this->party('customer', '9100000109');

        $entries = [];

        for ($i = 0; $i < 6; $i++) {
            $file = $this->fileFor($party);
            $file->remarks = 'Note '.$i;
            $file->save();

            $this->entry($party->id, '2026-03-0'.($i + 1), 'debit', 1000, 'From file '.$i, $file->id);
            $entries[] = PartyLedgerModel::where('work_file_id', $file->id)->first();
        }

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        $remarks = PartyLedgerModel::fileRemarks($entries);

        $this->assertSame(1, $queries, 'six files, one query');
        $this->assertCount(6, $remarks);
    }
}
