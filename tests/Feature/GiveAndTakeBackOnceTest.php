<?php

namespace Tests\Feature;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkFileStatusLogModel;
use App\Models\WorkTypeModel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Give to Vendor, Keep in-house, Take Back and Return to Customer, pressed twice.
 *
 * Found in the health check of 2026-10-07. A double click sent the batch twice,
 * and the second post read the folders while the first was still saving. It
 * saw them as they had been, wrote what the first had just written, and then
 * rebuilt the vendor's lines from that old picture — deleting the line the
 * first had made. Both said they had worked, and the vendor's statement lost
 * its credit for work they held, or its reversal for work they had handed
 * back. Return to Customer, hit the same way, ended on a server error with the
 * return already saved.
 *
 * Each of them now locks the ticked folders before it reads anything, so the
 * second waits for the first and is refused the way a stale page is. The race
 * itself needs two connections, which a test inside one transaction does not
 * have; what is pinned here is the order — the lock comes first and covers
 * every folder ticked — and that a second press, once the first is in, moves
 * nothing.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class GiveAndTakeBackOnceTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $sharma;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Pressed Twice Admin';
        $this->admin->email = 'pressed-twice-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer');
        $this->sharma = $this->party('vendor');

        // No paper list, so nothing holds the work up on its way out.
        $this->tr = new WorkTypeModel;
        $this->tr->name = 'TR '.uniqid();
        $this->tr->is_active = 1;
        $this->tr->save();
    }

    private function party(string $type): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.uniqid().' twice';
        $party->mobile = '93800'.random_int(10000, 99999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /**
     * A folder with one transfer on it: on the desk, out with Sharma at 700,
     * or back whole from Sharma.
     */
    private function folder(string $state = 'here'): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-TW-'.uniqid();
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01TW'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 0;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->tr->id;
        $item->customer_amount = 3000;
        $item->status = WorkFileModel::IN_OFFICE;

        if ($state !== 'here') {
            $item->vendor_id = $this->sharma->id;
            $item->vendor_amount = 700;
            $item->vendor_date = '2026-09-02';
            $item->status = $state === 'out' ? WorkFileModel::DISPATCHED : WorkFileModel::IN_OFFICE;
            $item->vendor_returned_on = $state === 'back' ? '2026-09-05' : null;
        }

        $item->save();

        $file->load('items');
        $file->rollUp();
        $file->save();
        $file->syncLedger();

        return $file->fresh();
    }

    private function job(WorkFileModel $file): WorkFileItemModel
    {
        return WorkFileItemModel::where('work_file_id', $file->id)->firstOrFail();
    }

    /** @param  array<int, WorkFileModel>  $files */
    private function give(array $files)
    {
        $jobs = array_map(fn ($file) => $this->job($file)->id, $files);

        return $this->actingAs($this->admin)->post(route('workfile.assign'), [
            'vendor_id' => $this->sharma->id,
            'vendor_date' => '2026-09-05',
            'files' => array_map(fn ($file) => $file->id, $files),
            'jobs' => $jobs,
            'amounts' => array_fill_keys($jobs, 700),
        ]);
    }

    /** @param  array<int, WorkFileModel>  $files */
    private function keep(array $files)
    {
        return $this->actingAs($this->admin)->post(route('workfile.keepinhouse'), [
            'files' => array_map(fn ($file) => $file->id, $files),
            'jobs' => array_map(fn ($file) => $this->job($file)->id, $files),
        ]);
    }

    /** @param  array<int, WorkFileModel>  $files */
    private function takeBack(array $files)
    {
        return $this->actingAs($this->admin)->post(route('workfile.vendorreturn'), [
            'returned_on' => '2026-09-10',
            'files' => array_map(fn ($file) => $file->id.':'.$this->sharma->id, $files),
            'remark' => 'Could not do it',
        ]);
    }

    /** @param  array<int, WorkFileModel>  $files */
    private function returnToCustomer(array $files)
    {
        return $this->actingAs($this->admin)->post(route('workfile.customerreturn'), [
            'returned_on' => '2026-09-10',
            'files' => array_map(fn ($file) => $file->id, $files),
            'remark' => 'Customer took the papers back',
        ]);
    }

    /** @return array<int, array{0: int, 1: float}> [party id, amount] for each line of one role */
    private function lines(WorkFileModel $file, string $role): array
    {
        return PartyLedgerModel::where('work_file_id', $file->id)->where('file_role', $role)
            ->get()->map(fn ($line) => [(int) $line->party_id, (float) $line->amount])->all();
    }

    private function history(WorkFileModel $file): int
    {
        return WorkFileStatusLogModel::where('work_file_id', $file->id)->count();
    }

    /**
     * The first statement the post runs inside its transaction, with what it
     * was asked about.
     *
     * @return array{0: string, 1: array}
     */
    private function firstInTransaction(callable $post): array
    {
        $inside = false;
        $first = null;

        Event::listen(TransactionBeginning::class, function () use (&$inside) {
            $inside = true;
        });

        DB::listen(function ($query) use (&$inside, &$first) {
            if ($inside && $first === null) {
                $first = [$query->sql, $query->bindings];
            }
        });

        $post();

        $this->assertNotNull($first, 'nothing was read in the transaction');

        return $first;
    }

    /**
     * The lock, and only the lock: every folder ticked, nothing else read on
     * the way. A plain read before it fixes what every read after it sees,
     * before the wait for the first press is over.
     *
     * @param  array{0: string, 1: array}  $first
     * @param  array<int, WorkFileModel>  $files
     */
    private function assertLocksFirst(array $first, array $files): void
    {
        [$sql, $bindings] = $first;

        $this->assertStringContainsString('for update', strtolower($sql), 'the first read in the transaction is not a lock: '.$sql);
        $this->assertStringNotContainsString('work_file_item', $sql, 'the lock reads the works as well, before it has waited for every folder');

        $ids = array_map(fn ($file) => (int) $file->id, $files);
        $locked = array_map('intval', $bindings);
        sort($ids);
        sort($locked);

        $this->assertSame($ids, $locked, 'not every folder ticked is locked before the reading starts');
    }

    // ------------------------------------------------------------ the lock

    public function test_give_to_vendor_locks_the_folders_before_it_reads(): void
    {
        $files = [$this->folder(), $this->folder()];

        $first = $this->firstInTransaction(fn () => $this->give($files)->assertRedirect(route('workfile.index')));

        $this->assertLocksFirst($first, $files);
    }

    public function test_keep_in_house_locks_the_folders_before_it_reads(): void
    {
        $files = [$this->folder(), $this->folder()];

        $first = $this->firstInTransaction(fn () => $this->keep($files)->assertRedirect(route('workfile.assign')));

        $this->assertLocksFirst($first, $files);
    }

    public function test_take_back_locks_the_folders_before_it_reads(): void
    {
        $files = [$this->folder('out'), $this->folder('out')];

        $first = $this->firstInTransaction(fn () => $this->takeBack($files)->assertRedirect(route('workfile.index')));

        $this->assertLocksFirst($first, $files);
    }

    public function test_return_to_customer_locks_the_folders_before_it_reads(): void
    {
        $files = [$this->folder(), $this->folder()];

        $first = $this->firstInTransaction(fn () => $this->returnToCustomer($files)->assertRedirect(route('workfile.index')));

        $this->assertLocksFirst($first, $files);
    }

    // ------------------------------------------------------- pressed twice

    public function test_give_to_vendor_pressed_twice_credits_the_vendor_once(): void
    {
        $file = $this->folder();

        $this->give([$file])->assertRedirect(route('workfile.index'))->assertSessionHas('success');
        $logged = $this->history($file);

        $this->give([$file])->assertSessionHas('error')->assertSessionMissing('success');

        $this->assertSame([[$this->sharma->id, 700.0]], $this->lines($file, 'vendor'));
        $this->assertSame($logged, $this->history($file), 'the second press wrote a second handover into the history');
    }

    public function test_keep_in_house_pressed_twice_is_refused(): void
    {
        $file = $this->folder('back');

        $this->keep([$file])->assertRedirect(route('workfile.assign'))->assertSessionHas('success');
        $logged = $this->history($file);

        $this->keep([$file])->assertSessionHas('error')->assertSessionMissing('success');

        $this->assertSame([], $this->lines($file, 'vendor'), 'work kept here left a line on the vendor\'s statement');
        $this->assertSame([], $this->lines($file, 'vendor_return'));
        $this->assertSame($logged, $this->history($file));
    }

    public function test_take_back_pressed_twice_reverses_once(): void
    {
        $file = $this->folder('out');

        $this->takeBack([$file])->assertRedirect(route('workfile.index'))->assertSessionHas('success');
        $logged = $this->history($file);

        $this->takeBack([$file])->assertSessionHas('error')->assertSessionMissing('success');

        $this->assertSame([[$this->sharma->id, 700.0]], $this->lines($file, 'vendor'));
        $this->assertSame([[$this->sharma->id, 700.0]], $this->lines($file, 'vendor_return'));
        $this->assertSame($logged, $this->history($file));
    }

    /** Refused in words, not a server error, and the refund is booked once. */
    public function test_return_to_customer_pressed_twice_is_refused_not_an_error(): void
    {
        $file = $this->folder();

        $this->returnToCustomer([$file])->assertRedirect(route('workfile.index'))->assertSessionHas('success');
        $logged = $this->history($file);

        $this->returnToCustomer([$file])->assertStatus(302)->assertSessionHas('error')->assertSessionMissing('success');

        $this->assertSame([[$this->customer->id, 3000.0]], $this->lines($file, 'customer_return'));
        $this->assertSame($logged, $this->history($file));
    }
}
