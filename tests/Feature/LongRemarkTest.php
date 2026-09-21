<?php

namespace Tests\Feature;

use App\Models\PaperTypeModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A line on a file's history that ran longer than the column holding it.
 *
 * work_file_status_log.remark is 255 characters. The paper checklist wrote every
 * pending paper into one line, and a transfer with eleven still to come ran to
 * nearly three hundred — the database refused it, and the checklist save it
 * was describing was lost with it. On the live site, on 21 September, for file
 * 57.
 *
 * The papers here are that file's papers, word for word.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class LongRemarkTest extends TestCase
{
    use DatabaseTransactions;

    /** What file 57 was waiting on when its checklist failed to save. */
    private const ELEVEN = [
        'Insurance certificate',
        'PUC certificate',
        "Buyer's PAN card",
        "Buyer's undertaking",
        'Seller Passport-size photographs',
        'Form 34',
        'Loan agreement / sanction copy',
        'Buyer Passport-size photographs',
        'Buyer Aadhar',
        'Delivary Photo',
        'Email Confirmation',
    ];

    private function remark(bool $firstLook, array $moved, array $pending): string
    {
        $method = new ReflectionMethod(WorkFileModel::class, 'papersRemark');
        $method->setAccessible(true);

        return $method->invoke(null, $firstLook, $moved, $pending);
    }

    private function file(): WorkFileModel
    {
        $file = new WorkFileModel;
        $file->file_no = 'F-LR-'.uniqid();
        $file->received_date = now()->toDateString();
        $file->registration_no = 'BR01LR'.random_int(1000, 9999);
        $file->work_type_id = $this->anyWorkType()->id;
        $file->customer_id = $this->anyParty('customer')->id;
        $file->customer_amount = 5000;
        $file->status = WorkFileModel::IN_OFFICE;
        $file->save();

        return $file;
    }

    // --------------------------------------------------- what the line says

    public function test_file_57s_papers_fit_on_one_line(): void
    {
        $said = $this->remark(true, [], self::ELEVEN);

        $this->assertLessThanOrEqual(WorkFileModel::REMARK_LIMIT, mb_strlen($said));
    }

    public function test_it_names_what_fits_and_counts_the_rest(): void
    {
        $said = $this->remark(true, [], self::ELEVEN);

        $this->assertStringStartsWith('Papers checked. Pending: Insurance certificate, PUC certificate', $said);
        $this->assertMatchesRegularExpression('/ and \d+ more\.$/', $said);

        // Not a stub: it keeps every name it has room for.
        $this->assertGreaterThanOrEqual(8, substr_count($said, ',') + 1);
    }

    /** A short list is written out whole, exactly as it always was. */
    public function test_a_list_that_fits_is_untouched(): void
    {
        $this->assertSame(
            'Papers checked. Pending: Form 30, RC.',
            $this->remark(true, [], ['Form 30', 'RC'])
        );
    }

    public function test_it_says_so_when_everything_has_arrived(): void
    {
        $this->assertSame('Papers checked. All papers received.', $this->remark(true, [], []));
    }

    /** Three long lists at once share the room rather than one crowding out the rest. */
    public function test_three_long_lists_still_fit_and_each_keeps_a_name(): void
    {
        $said = $this->remark(false, ['received' => self::ELEVEN, 'not_needed' => self::ELEVEN], self::ELEVEN);

        $this->assertLessThanOrEqual(WorkFileModel::REMARK_LIMIT, mb_strlen($said));
        $this->assertStringContainsString('Received: Insurance certificate', $said);
        $this->assertStringContainsString('Not needed: Insurance certificate', $said);
        $this->assertStringContainsString('Pending: Insurance certificate', $said);
    }

    /** Never "Pending: and 11 more": a line always names at least one. */
    public function test_one_enormous_name_is_still_named(): void
    {
        $said = $this->remark(true, [], [str_repeat('Very long paper name ', 20), 'Form 30']);

        $this->assertStringContainsString('Pending: Very long paper name', $said);
    }

    // ---------------------------------------------- nothing can fail the save

    /**
     * Whatever a remark holds, writing it does not throw.
     *
     * The last resort, for the next generated line nobody has thought to keep
     * short: cut at the limit and marked as cut, rather than the database
     * refusing it and the save around it going down too.
     */
    public function test_an_overlong_remark_is_cut_rather_than_refused(): void
    {
        $file = $this->file();

        $log = $file->logStatus(null, str_repeat('This is far too long. ', 30));

        $saved = $log->fresh()->remark;

        // At most the limit, not exactly it: a space left at the cut is trimmed
        // so the line reads "long…" rather than "long …".
        $this->assertLessThanOrEqual(WorkFileModel::REMARK_LIMIT, mb_strlen($saved));
        $this->assertGreaterThan(WorkFileModel::REMARK_LIMIT - 5, mb_strlen($saved), 'it cut far more than it had to');
        $this->assertStringEndsWith('…', $saved);
    }

    public function test_a_remark_that_fits_is_saved_as_written(): void
    {
        $file = $this->file();

        $log = $file->logStatus(null, 'Sent for verification');

        $this->assertSame('Sent for verification', $log->fresh()->remark);
    }

    // ------------------------------------------------------- the save itself

    /**
     * File 57, end to end: eleven papers pending, and the checklist saves.
     */
    public function test_a_checklist_with_eleven_papers_pending_saves(): void
    {
        $this->actingAs($this->admin());

        $type = new WorkTypeModel;
        $type->name = 'TR '.uniqid();
        $type->is_active = 1;
        $type->save();

        $papers = [];

        foreach (self::ELEVEN as $name) {
            $paper = new PaperTypeModel;
            $paper->name = $name.' '.uniqid();
            $paper->save();
            $paper->setNeeds([$type->id => 'required']);
            $papers[] = $paper;
        }

        $file = $this->file();
        $file->work_type_id = $type->id;
        $file->save();

        $item = new WorkFileItemModel;
        $item->work_file_id = $file->id;
        $item->work_type_id = $type->id;
        $item->customer_amount = 5000;
        $item->status = WorkFileModel::IN_OFFICE;
        $item->save();

        $answers = [];

        foreach ($papers as $paper) {
            $answers[$paper->id] = ['state' => 'pending'];
        }

        $file->fresh()->savePaperChecklist($answers);

        $said = $file->fresh()->statusLog()->latest('id')->value('remark');

        $this->assertNotNull($said, 'the checklist saved nothing to the history');
        $this->assertLessThanOrEqual(WorkFileModel::REMARK_LIMIT, mb_strlen($said));
        $this->assertStringContainsString('Pending:', $said);
    }

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Long Remark Admin';
        $user->email = 'long-remark-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }
}
