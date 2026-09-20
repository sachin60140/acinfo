<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Moving a file along from the report it was noticed on.
 *
 * The dialog posts to the status controller with that screen's own field names,
 * so none of its rules are retested here — they are tested where they live. What
 * is new is the trip back: the report sends the page it was showing so the
 * reader does not lose the customer, dates and status they filtered to in order
 * to find the file, and that page arrives in the form body.
 *
 * A URL that arrives in a form body and is redirected to unchecked is how a link
 * that looks like a page of this application lands somebody on a copy of the
 * login screen somewhere else. That is the part worth a suite.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class WorkReportUpdateTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Report Update Admin';
        $user->email = 'report-update-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    private function customer(): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = 'customer';
        $party->name = 'Report Update Customer';
        $party->mobile = '9300000'.random_int(100, 999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function file(PartyModel $customer): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-'.substr((string) microtime(true), -6);
        $file->received_date = '2026-09-01';
        $file->work_type_id = $this->anyWorkType()->id;
        $file->customer_id = $customer->id;
        $file->customer_amount = 5000;
        $file->status = 'in_office';
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $file->work_type_id;
        $item->customer_amount = 5000;
        $item->status = 'in_office';
        $item->save();

        return $file;
    }

    private function save(WorkFileItemModel|int $item, array $extra = [])
    {
        $id = $item instanceof WorkFileItemModel ? $item->id : $item;

        return $this->actingAs($this->admin())->post('/admin/file/status', array_merge([
            'statuses' => [$id => 'part_pesi_required'],
            'remarks' => [$id => 'Chased the RTO'],
        ], $extra));
    }

    private function itemOf(WorkFileModel $file): WorkFileItemModel
    {
        return WorkFileItemModel::where('work_file_id', $file->id)->firstOrFail();
    }

    // ------------------------------------------------------- the trip back

    public function test_a_change_made_from_the_report_returns_to_the_report(): void
    {
        $file = $this->file($this->customer());

        $this->save($this->itemOf($file), [
            'return_to' => '/admin/reports/files?party_id=7&status=open',
        ])->assertRedirect(url('/admin/reports/files?party_id=7&status=open'));

        $this->assertSame('part_pesi_required', $this->itemOf($file)->fresh()->status, 'and it saved');
    }

    /** The status board sends none, and still lands on itself as before. */
    public function test_a_change_made_from_the_board_still_returns_to_the_board(): void
    {
        $file = $this->file($this->customer());

        $this->save($this->itemOf($file))
            ->assertRedirect(route('workfile.status'));
    }

    /**
     * The one that matters. Every one of these is a page somewhere else wearing
     * this application's address bar.
     */
    public static function elsewhere(): array
    {
        return [
            'another site' => ['https://evil.test/login'],
            'protocol relative' => ['//evil.test/login'],
            'a scheme that is not the web' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'credentials in the host' => ['https://acinfo.in@evil.test/login'],
            'a backslash the browser reads as a slash' => ['/\\evil.test/login'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('elsewhere')]
    public function test_it_never_redirects_away_from_this_site(string $url): void
    {
        $file = $this->file($this->customer());

        $response = $this->save($this->itemOf($file), ['return_to' => $url]);

        $location = (string) $response->headers->get('Location');

        $this->assertStringStartsWith(
            url('/'),
            $location,
            "it redirected to $url"
        );

        $this->assertStringNotContainsString('evil.test', $location);
    }

    /** Our own absolute URL is us, and is followed. */
    public function test_an_absolute_url_on_this_site_is_followed(): void
    {
        $file = $this->file($this->customer());

        $this->save($this->itemOf($file), [
            'return_to' => url('/admin/reports/files?party_id=7'),
        ])->assertRedirect(url('/admin/reports/files?party_id=7'));
    }

    public function test_an_empty_return_falls_back_to_the_board(): void
    {
        $file = $this->file($this->customer());

        $this->save($this->itemOf($file), ['return_to' => '   '])
            ->assertRedirect(route('workfile.status'));
    }

    // ------------------------------------------------- what the report sends

    public function test_the_report_sends_the_works_each_row_can_move(): void
    {
        $customer = $this->customer();
        $file = $this->file($customer);

        $props = $this->actingAs($this->admin())
            ->getJson(route('report.files', ['party_type' => 'customer', 'party_id' => $customer->id]))
            ->assertOk()
            ->json('props');

        $row = collect($props['rows'])->firstWhere('file_no', $file->file_no);

        $this->assertNotNull($row, 'the file is on the report');
        $this->assertCount(1, $row['items'], 'with its works');
        $this->assertSame($this->itemOf($file)->id, $row['items'][0]['id'], 'addressed by work id');
        $this->assertSame('in_office', $row['items'][0]['status']);
        $this->assertFalse($row['items'][0]['has_screenshot']);
        $this->assertSame('Update', $row['update']);
    }

    /** The dialog needs somewhere to post and the statuses to offer. */
    public function test_the_report_sends_what_the_dialog_needs(): void
    {
        $customer = $this->customer();
        $this->file($customer);

        $props = $this->actingAs($this->admin())
            ->getJson(route('report.files', ['party_type' => 'customer', 'party_id' => $customer->id]))
            ->assertOk()
            ->json('props');

        $this->assertSame(route('workfile.status'), $props['action']);
        $this->assertNotEmpty($props['csrf']);
        $this->assertSame(WorkFileModel::JOB_STATUSES, $props['jobStatuses']);
        $this->assertSame(WorkFileModel::APPROVED, $props['approvedKey']);
        $this->assertContains(WorkFileModel::CANCELLED, $props['reasonKeys']);

        // The report it was read from, so the trip back lands where it started.
        $this->assertStringContainsString('party_id='.$customer->id, $props['returnTo']);
    }

    public function test_the_update_column_is_kept_out_of_the_exports(): void
    {
        $customer = $this->customer();
        $this->file($customer);

        $column = collect($this->actingAs($this->admin())
            ->getJson(route('report.files', ['party_type' => 'customer', 'party_id' => $customer->id]))
            ->assertOk()
            ->json('props.columns'))
            ->firstWhere('key', 'update');

        $this->assertNotNull($column);
        $this->assertSame('action', $column['type']);
        // A column of the word "Update" is not data, and searched, every row
        // matches anyone typing it.
        $this->assertFalse($column['exportable']);
        $this->assertFalse($column['searchable']);
    }
}
