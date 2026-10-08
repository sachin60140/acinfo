<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Lists of finished work, a page at a time.
 *
 * Found in the health check: Update Status's Approval Done and All tabs, All
 * Work Files and the Work Report drew every file ever kept, a row built in
 * PHP for each — 15 seconds and 300 MB for Approval Done on a copy with
 * 40,000 files, past what a shared host allows a page. Nearly every file ends
 * up approved, so those lists only ever grow.
 *
 * Now they show the newest WorkFileModel::LIST_LIMIT, say how many there are
 * in all, link to the older ones, and say that their totals cover only what
 * is shown. Work in hand is still shown whole, in its own order.
 *
 * Files are put straight into the tables here, in bulk: passing the limit
 * through the models would take minutes. Each is received on a day of its
 * own, long ago, so the newest and the oldest are never in doubt and nothing
 * the database already holds falls inside the dates asked for.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class LongListsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    private WorkTypeModel $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Long Lists Admin';
        $this->admin->email = 'long-lists-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->vendor = $this->party('vendor');

        $this->type = new WorkTypeModel;
        $this->type->name = 'LL '.uniqid();
        $this->type->is_active = 1;
        $this->type->save();
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = 'Long list '.$type.' '.uniqid();
        $party->mobile = '9'.random_int(600000000, 999999999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /**
     * Folders of one work each, received a day apart from $firstDay on.
     *
     * @param  array  $folder  columns to set on every folder and its work
     * @return array<int, string> their file numbers, oldest first
     */
    private function files(int $count, string $status, string $firstDay = '1998-01-01', array $folder = []): array
    {
        $prefix = 'F-LL-'.uniqid().'-';
        $now = now();
        $rows = [];

        for ($n = 0; $n < $count; $n++) {
            $rows[] = [
                'file_no' => $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'received_date' => date('Y-m-d', strtotime($firstDay.' +'.$n.' days')),
                'work_type_id' => $this->type->id,
                'customer_id' => $this->customer->id,
                'customer_amount' => 100,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ] + $folder;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('work_file')->insert($chunk);
        }

        $made = DB::table('work_file')->where('file_no', 'like', $prefix.'%')
            ->orderBy('received_date')->pluck('id', 'file_no');

        $works = [];

        foreach ($made as $id) {
            $works[] = [
                'work_file_id' => $id,
                'work_type_id' => $this->type->id,
                'customer_amount' => 100,
                'status' => $status,
                'vendor_id' => $folder['vendor_id'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($works, 200) as $chunk) {
            DB::table('work_file_item')->insert($chunk);
        }

        return $made->keys()->all();
    }

    private function payload(string $url): array
    {
        return $this->actingAs($this->admin)->getJson($url)->assertOk()->json();
    }

    private function html(string $url): string
    {
        return $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
    }

    // ------------------------------------------------------------ Update Status

    /** The file numbers on the board, in the order it draws them. */
    private function onBoard(array $json): array
    {
        return array_column($json['props']['files'], 'file_no');
    }

    public function test_approval_done_on_the_board_is_the_newest_page_and_says_so(): void
    {
        $limit = WorkFileModel::LIST_LIMIT;
        $made = $this->files($limit + 2, WorkFileModel::APPROVED);
        $newestFirst = array_reverse($made);

        foreach (['approval_done', 'all'] as $tab) {
            $url = route('workfile.status', ['status' => $tab, 'work_type' => $this->type->id]);
            $json = $this->payload($url);

            // The newest, newest first: what was approved lately is what is asked about.
            $this->assertSame(array_slice($newestFirst, 0, $limit), $this->onBoard($json), $tab);

            $shown = $json['page']['shown'] ?? null;
            $this->assertNotNull($shown, "$tab says it is not the whole list");
            $this->assertSame([1, $limit, $limit + 2], [$shown['first'], $shown['last'], $shown['total']], $tab);
            $this->assertNull($shown['newer']);
            $this->assertStringContainsString('page=2', (string) $shown['older']);
            // The older page is the same tab, of the same work type.
            $this->assertStringContainsString('status='.$tab, (string) $shown['older']);
            $this->assertStringContainsString('work_type='.$this->type->id, (string) $shown['older']);

            $html = $this->html($url);
            $this->assertStringContainsString('Showing the newest '.number_format($limit).' of '.number_format($limit + 2).' files', $html);
            $this->assertStringContainsString('Search looks through these only', $html);

            // And the two oldest are on the next page.
            $older = $this->payload($url.'&page=2');
            $this->assertSame(array_slice($newestFirst, $limit), $this->onBoard($older), "$tab, older");
            $this->assertSame([$limit + 1, $limit + 2], [$older['page']['shown']['first'], $older['page']['shown']['last']]);
            $this->assertNull($older['page']['shown']['older']);
            $this->assertStringContainsString('page=1', (string) $older['page']['shown']['newer']);
            // Reset clears what was typed, not which page it was typed on.
            $this->assertStringContainsString('page=2', $older['props']['resetUrl']);
        }
    }

    /** A page past the last is the last, so an old link still shows something. */
    public function test_a_page_past_the_end_of_the_board_is_its_last(): void
    {
        $made = $this->files(WorkFileModel::LIST_LIMIT + 1, WorkFileModel::APPROVED);

        $json = $this->payload(route('workfile.status', ['status' => 'approval_done', 'work_type' => $this->type->id, 'page' => 9]));

        $this->assertSame([$made[0]], $this->onBoard($json));
    }

    public function test_work_in_hand_on_the_board_is_still_shown_whole(): void
    {
        $limit = WorkFileModel::LIST_LIMIT;
        $made = $this->files($limit + 2, WorkFileModel::IN_OFFICE);

        foreach (['open', WorkFileModel::IN_OFFICE] as $tab) {
            $url = route('workfile.status', ['status' => $tab, 'work_type' => $this->type->id]);
            $json = $this->payload($url);

            // Every one, oldest first, as ever: the one waiting longest is the one to chase.
            $this->assertSame($made, $this->onBoard($json), $tab);
            $this->assertNull($json['page']['shown'] ?? null, $tab);
            $this->assertStringNotContainsString('Showing the newest', $this->html($url));
        }
    }

    /** With nothing to page, the board says nothing about pages. */
    public function test_a_short_list_of_finished_work_is_shown_whole_and_says_nothing(): void
    {
        $made = $this->files(3, WorkFileModel::APPROVED);

        $url = route('workfile.status', ['status' => 'approval_done', 'work_type' => $this->type->id]);
        $json = $this->payload($url);

        $this->assertSame(array_reverse($made), $this->onBoard($json));
        $this->assertNull($json['page']['shown'] ?? null);
        $this->assertStringNotContainsString('Showing the newest', $this->html($url));
    }

    // ---------------------------------------------------------- All Work Files

    /** The rows' file numbers, in the order the list sends them. */
    private function listed(array $json): array
    {
        return array_column($json['props']['rows'], 'file_no');
    }

    public function test_all_work_files_is_the_newest_page_and_its_totals_say_so(): void
    {
        $limit = WorkFileModel::LIST_LIMIT;
        $made = $this->files($limit + 2, WorkFileModel::APPROVED);
        $newestFirst = array_reverse($made);

        foreach ([
            route('workfile.index', ['from' => '1998-01-01', 'to' => '1999-12-31']),
            route('workfile.index', ['status' => WorkFileModel::APPROVED, 'from' => '1998-01-01', 'to' => '1999-12-31']),
            // The same list, turned round to the approvals.
            route('workfile.approved', ['from' => '1998-01-01', 'to' => '1999-12-31']),
        ] as $url) {
            $json = $this->payload($url);

            $this->assertSame(array_slice($newestFirst, 0, $limit), $this->listed($json), $url);
            $this->assertSame($limit + 2, $json['page']['shown']['total'] ?? null, $url);

            // The figures above the list are of the page, and say so.
            $this->assertEqualsWithDelta($limit * 100, $json['page']['billed'], 0.001, $url);
            $this->assertSame('Total of those shown', $json['props']['totalLabel'], $url);

            $html = $this->html($url);
            $this->assertStringContainsString('Showing the newest '.number_format($limit).' of '.number_format($limit + 2).' files', $html);
            // Under Billed, Cost and Margin, each.
            $this->assertSame(3, substr_count($html, '<span class="stat-note">of the '.number_format($limit).' files shown</span>'), $url);

            $this->assertSame(array_slice($newestFirst, $limit), $this->listed($this->payload($url.'&page=2')), "$url, older");
        }
    }

    /** A margin that cannot be worked out yet is still said to be of the page. */
    public function test_all_work_files_says_whose_margin_it_is_when_rates_are_still_to_agree(): void
    {
        $limit = WorkFileModel::LIST_LIMIT;
        // Given out, with no rate agreed.
        $this->files($limit + 1, WorkFileModel::APPROVED, '1998-01-01', ['vendor_id' => $this->vendor->id]);

        $html = $this->html(route('workfile.index', ['from' => '1998-01-01', 'to' => '1999-12-31']));

        $this->assertStringContainsString(
            'on 0 of the '.number_format($limit).' files shown &mdash; '.$limit.' awaiting a price',
            $html
        );
    }

    public function test_work_in_hand_on_all_work_files_is_still_whole(): void
    {
        $limit = WorkFileModel::LIST_LIMIT;
        $made = $this->files($limit + 2, WorkFileModel::IN_OFFICE);

        $json = $this->payload(route('workfile.index', ['status' => 'open', 'from' => '1998-01-01', 'to' => '1999-12-31']));

        $this->assertSame($made, $this->listed($json));
        $this->assertNull($json['page']['shown'] ?? null);
        $this->assertSame('Total', $json['props']['totalLabel']);
    }

    // ------------------------------------------------------------- Work Report

    /** The file numbers the report draws, in band order. */
    private function reported(array $json): array
    {
        return array_column($json['props']['rows'], 'file_no');
    }

    public function test_the_work_report_is_the_newest_page_and_its_totals_say_so(): void
    {
        $limit = WorkFileModel::LIST_LIMIT;
        $made = $this->files($limit + 2, WorkFileModel::APPROVED);

        $url = route('report.files', ['party_type' => 'customer', 'party_id' => $this->customer->id]);
        $json = $this->payload($url);

        // The newest, banded as ever: one party, oldest first within it.
        $this->assertSame(array_slice($made, 2), $this->reported($json));
        $this->assertSame($limit + 2, $json['page']['shown']['total'] ?? null);
        $this->assertSame($limit, $json['page']['totals']['files']);
        $this->assertSame('Total of those shown', $json['props']['totalLabel']);

        $html = $this->html($url);
        $this->assertStringContainsString('Showing the newest '.number_format($limit).' of '.number_format($limit + 2).' files', $html);
        // The count of files, then Billed, Cost and Margin, each said to be of the page.
        $this->assertStringContainsString('shown, of '.number_format($limit + 2), $html);
        $this->assertSame(3, substr_count($html, '<span class="stat-note">of the '.number_format($limit).' files shown</span>'));
        $this->assertStringContainsString('Total of the '.number_format($limit).' files shown', $html);
        $this->assertStringNotContainsString('Grand Total', $html);

        $this->assertSame(array_slice($made, 0, 2), $this->reported($this->payload($url.'&page=2')));
    }

    public function test_work_in_hand_on_the_work_report_is_still_whole(): void
    {
        $limit = WorkFileModel::LIST_LIMIT;
        $made = $this->files($limit + 2, WorkFileModel::IN_OFFICE);

        $url = route('report.files', ['party_type' => 'customer', 'party_id' => $this->customer->id, 'status' => 'open']);
        $json = $this->payload($url);

        $this->assertSame($made, $this->reported($json));
        $this->assertNull($json['page']['shown'] ?? null);
        $this->assertStringContainsString('Grand Total', $this->html($url));
    }

    /**
     * Vendor-wise, a folder the vendor holds only part of is drawn from its
     * works rather than by the query the rest come from — and is still placed
     * among them by when it came in.
     */
    public function test_the_vendor_wise_report_pages_a_folder_held_in_part_among_the_rest(): void
    {
        $limit = WorkFileModel::LIST_LIMIT;
        $whole = $this->files($limit, WorkFileModel::APPROVED, '1998-01-01', ['vendor_id' => $this->vendor->id]);

        // The newest of all: given in part, a second work kept in the office.
        [$part] = $this->files(1, WorkFileModel::APPROVED, '2001-01-01', ['vendor_id' => $this->vendor->id]);
        $folder = DB::table('work_file')->where('file_no', $part)->value('id');
        DB::table('work_file_item')->insert([
            'work_file_id' => $folder,
            'work_type_id' => $this->type->id,
            'customer_amount' => 100,
            'status' => WorkFileModel::APPROVED,
            'vendor_id' => null,
        ]);

        $url = route('report.files', ['party_type' => 'vendor', 'party_id' => $this->vendor->id]);
        $json = $this->payload($url);

        $this->assertSame($limit + 1, $json['page']['shown']['total'] ?? null);
        $this->assertSame([...array_slice($whole, 1), $part], $this->reported($json));
        $this->assertSame([$whole[0]], $this->reported($this->payload($url.'&page=2')));

        // No rate agreed on any of them, so no margin — said of the page too.
        $this->assertStringContainsString(
            'on 0 of the '.number_format($limit).' files shown &mdash; '.$limit.' awaiting a price',
            $this->html($url)
        );
    }
}
