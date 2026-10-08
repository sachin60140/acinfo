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
 * A file number typed on the edit screen that Receive Files gives out later.
 *
 * Received files are numbered F- and their id (generateFileNo), and the column
 * is unique. The edit screen let the office type any number no file had yet,
 * so a file renamed to F-00150 while the newest id was 120 was sitting on the
 * 150th file's number. When Receive Files got there, the whole batch at the
 * counter failed with a server error and every row typed into it was lost.
 *
 * Two halves, so neither has to be perfect: the edit screen keeps the F- and
 * digits form for Receive Files, and Receive Files steps past a number that
 * is taken anyway — by a rename made before the edit screen refused one.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class FileNumberTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private WorkTypeModel $tr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'File Number Admin';
        $this->admin->email = 'file-number-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = new PartyModel;
        $this->customer->party_type = 'customer';
        $this->customer->name = 'Customer '.uniqid().' file number';
        $this->customer->mobile = '93800'.random_int(10000, 99999);
        $this->customer->is_active = 1;
        $this->customer->save();

        $this->tr = new WorkTypeModel;
        $this->tr->name = 'TR '.uniqid();
        $this->tr->is_active = 1;
        $this->tr->save();
    }

    /** A file of one work, carrying $number, or its own automatic one. */
    private function file(?string $number = null): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->received_date = '2026-09-01';
        $file->registration_no = 'BR01FN'.random_int(1000, 9999);
        $file->work_type_id = $this->tr->id;
        $file->customer_id = $this->customer->id;
        $file->customer_amount = 3000;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        $file->file_no = $number ?? self::automatic($file->id);
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $this->tr->id;
        $item->customer_amount = 3000;
        $item->status = WorkFileModel::IN_OFFICE;
        $item->save();

        $file->syncLedger();

        return $file->fresh();
    }

    /** The running number Receive Files gives the file with this id. */
    private static function automatic(int $id): string
    {
        return 'F-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    /**
     * A number Receive Files gives out after this file. Far ahead, so no other
     * run sharing the database reaches it while this one is using it.
     */
    private static function later(WorkFileModel $file): string
    {
        return self::automatic($file->id + 1000000);
    }

    /** The edit page saved as drawn, with the File No. box typed over. */
    private function rename(WorkFileModel $file, string $number)
    {
        $page = $this->actingAs($this->admin)
            ->getJson(route('workfile.edit', $file->id))->assertOk()->json('props');

        $values = $page['values'];

        return $this->actingAs($this->admin)
            ->from(route('workfile.edit', $file->id))
            ->post(route('workfile.edit', $file->id), [
                'file_no' => $number,
                'received_date' => '2026-09-01',
                'work_type_id' => $values['work_type_id'],
                'registration_no' => $values['registration_no'],
                'customer_id' => $values['customer_id'],
                'customer_amount' => $values['customer_amount'],
                'status' => $values['status'],
                'drawn' => $page['drawn'],
                'was_status' => $page['wasStatus'],
            ]);
    }

    /**
     * Take papers in, the way the Receive Files screen posts them. Each row
     * a vehicle of its own, none of them one file() made, so no row is
     * refused as work already in hand.
     */
    private function receive(int $rows = 1)
    {
        $first = random_int(1000, 9000);

        return $this->actingAs($this->admin)->post(route('workfile.receive'), [
            'received_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'rows' => array_map(fn ($row) => [
                'registration_no' => 'BR01FR'.($first + $row),
                'works' => [['work_type_id' => $this->tr->id, 'amount' => '3000']],
            ], range(1, $rows)),
        ]);
    }

    /**
     * The next file received finds its number already on $holders — the
     * first holding the number itself, the next that number stepped once —
     * as files renamed on the edit screen before it refused would.
     *
     * Which id that file gets cannot be known beforehand: the database is
     * shared with other runs, and their inserts take ids too. So the numbers
     * are given to the holders the moment the file has its id, which is
     * before it is numbered.
     */
    private function takeTheNextNumber(WorkFileModel ...$holders): void
    {
        $done = false;

        WorkFileModel::created(function (WorkFileModel $file) use ($holders, &$done) {
            if ($done) {
                return;
            }

            $done = true;

            foreach ($holders as $index => $holder) {
                $holder->file_no = self::automatic($file->id).($index ? '-'.($index + 1) : '');
                $holder->saveQuietly();
            }
        });
    }

    /** The files Receive Files made for this customer, leaving out $holders. */
    private function received(WorkFileModel ...$holders)
    {
        return WorkFileModel::where('customer_id', $this->customer->id)
            ->whereKeyNot(array_map(fn ($holder) => $holder->id, $holders));
    }

    // ---------------------------------------------------------- the edit screen

    /** The reported case: a number Receive Files has not reached yet. */
    public function test_a_file_cannot_be_renamed_to_a_number_receive_files_gives_out_later(): void
    {
        $file = $this->file();
        $later = self::later($file);

        $this->rename($file, $later)
            ->assertRedirect(route('workfile.edit', $file->id))
            ->assertSessionHasErrors('file_no');

        $this->assertSame(self::automatic($file->id), $file->fresh()->file_no, 'the number was changed');
    }

    /** The column ignores case, so f-00150 is the same number as F-00150. */
    public function test_nor_to_one_typed_in_small_letters(): void
    {
        $file = $this->file();

        $this->rename($file, strtolower(self::later($file)))
            ->assertSessionHasErrors('file_no');

        $this->assertSame(self::automatic($file->id), $file->fresh()->file_no);
    }

    /** One already used by an earlier file is Receive Files' too, not the office's. */
    public function test_nor_to_an_earlier_files_number_that_is_free_again(): void
    {
        $earlier = $this->file('OLD-'.uniqid());
        $file = $this->file();

        $this->rename($file, self::automatic($earlier->id))
            ->assertSessionHasErrors('file_no');

        $this->assertSame(self::automatic($file->id), $file->fresh()->file_no);
    }

    /** Saving the page as drawn, which posts the number back unchanged, is not refused. */
    public function test_a_file_keeps_its_own_number(): void
    {
        $file = $this->file();

        $this->rename($file, self::automatic($file->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workfile.index'));

        $this->assertSame(self::automatic($file->id), $file->fresh()->file_no);
    }

    /** Its own in small letters is still its own: to the column it is the same number. */
    public function test_its_own_number_in_small_letters_is_not_refused(): void
    {
        $file = $this->file();

        $this->rename($file, strtolower(self::automatic($file->id)))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workfile.index'));
    }

    /** Renamed once, a file can be given back the number Receive Files gave it. */
    public function test_a_file_can_take_its_own_automatic_number_back(): void
    {
        $file = $this->file('RTO-'.uniqid());

        $this->rename($file, self::automatic($file->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(self::automatic($file->id), $file->fresh()->file_no);
    }

    /**
     * A file already carrying someone else's F-number, from a rename made
     * before this rule, is still saved: refusing its own number on every save
     * would lock the office out of correcting anything else on it.
     */
    public function test_a_number_the_file_already_carries_is_not_refused(): void
    {
        $file = $this->file();
        $file->file_no = self::later($file);
        $file->save();

        $this->rename($file, self::later($file))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workfile.index'));

        $this->assertSame(self::later($file), $file->fresh()->file_no);
    }

    /** Any other number is the office's to type, as before. */
    public function test_a_number_in_another_form_can_still_be_typed(): void
    {
        $file = $this->file();
        $typed = 'RTO/'.random_int(1000, 9999);

        $this->rename($file, $typed)->assertSessionHasNoErrors();

        $this->assertSame($typed, $file->fresh()->file_no);
    }

    // ---------------------------------------------------------- Receive Files

    /**
     * The reported failure: the next file's number is already taken. The
     * batch is received, and the newcomer carries its own number marked as
     * the second of that name.
     */
    public function test_receive_steps_past_a_number_already_taken(): void
    {
        $holder = $this->file('OLD-'.uniqid());
        $this->takeTheNextNumber($holder);

        $this->receive()->assertRedirect(route('workfile.index'))->assertSessionHas('success');

        $received = $this->received($holder)->sole();
        $own = self::automatic($received->id);

        $this->assertSame($own, $holder->fresh()->file_no, 'the premise: its number was already taken');
        $this->assertSame($own.'-2', $received->file_no);
        $this->assertStringContainsString($own.'-2', session('success'), 'the counter is told the number it got');
    }

    /** And past that one too, if somebody typed it. */
    public function test_receive_steps_past_every_number_already_taken(): void
    {
        $first = $this->file('OLD-'.uniqid());
        $second = $this->file('OLD-'.uniqid());
        $this->takeTheNextNumber($first, $second);

        $this->receive()->assertRedirect(route('workfile.index'))->assertSessionHas('success');

        $received = $this->received($first, $second)->sole();
        $own = self::automatic($received->id);

        $this->assertSame($own.'-2', $second->fresh()->file_no, 'the premise: the next one was taken too');
        $this->assertSame($own.'-3', $received->file_no);
    }

    /** The files after it are back on their own numbers, not shifted along by one. */
    public function test_only_the_clashing_file_is_stepped(): void
    {
        $holder = $this->file('OLD-'.uniqid());
        $this->takeTheNextNumber($holder);

        $this->receive(3)->assertRedirect(route('workfile.index'));

        $received = $this->received($holder)->orderBy('id')->get();

        $this->assertCount(3, $received);
        $this->assertSame(self::automatic($received[0]->id).'-2', $received[0]->file_no);
        $this->assertSame(self::automatic($received[1]->id), $received[1]->file_no);
        $this->assertSame(self::automatic($received[2]->id), $received[2]->file_no);
    }

    /** A file numbered that way is saved on the edit screen like any other. */
    public function test_a_stepped_number_is_kept_on_the_edit_screen(): void
    {
        $holder = $this->file('OLD-'.uniqid());
        $this->takeTheNextNumber($holder);
        $this->receive()->assertRedirect(route('workfile.index'));

        $received = $this->received($holder)->sole();

        $this->rename($received, $received->file_no)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workfile.index'));

        $this->assertSame(self::automatic($received->id).'-2', $received->fresh()->file_no);
    }

    /** Nothing taken: the number is the one it always was. */
    public function test_an_untaken_number_is_unchanged(): void
    {
        $this->receive()->assertRedirect(route('workfile.index'));

        $received = $this->received()->sole();

        $this->assertSame(self::automatic($received->id), $received->file_no);
    }
}
