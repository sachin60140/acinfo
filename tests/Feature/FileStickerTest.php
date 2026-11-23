<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A sticker for every file received.
 *
 * Asked for by the owner on 2026-11-19: a 4x6 inch thermal sticker pasted on
 * each folder, saying the customer and their mobile, the vehicle, the vehicle
 * owner's mobile, the lender if an HPT is asked for, the financer for an HPA,
 * the seller and buyer for a transfer (TR, or TO as the owner put it), and
 * the works.
 *
 * Which work asks for which is a setting on its type (work_type.asks_for),
 * not a reading of its name. Read for: the setting is kept and checked; a file
 * is not received without the owner's mobile and what its works ask for, the
 * seller's mobile aside, while Edit File asks for none of them; a file keeps
 * only what its works ask for; an edit sent without a box leaves what is
 * stored; the sticker says what was asked, a line where nothing was typed,
 * and never the vendor; and the buttons that lead to it.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class FileStickerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private PartyModel $customer;

    private PartyModel $vendor;

    private WorkTypeModel $hpt;

    private WorkTypeModel $hpa;

    private WorkTypeModel $to;

    private WorkTypeModel $fitness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'Sticker Admin';
        $this->admin->email = 'sticker-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();

        $this->customer = $this->party('customer', 'Shree Balaji Motors '.uniqid());
        $this->vendor = $this->party('vendor', 'Sharma DTO Services '.uniqid());

        $this->hpt = $this->type('HPT', WorkTypeModel::ASKS_LENDER);
        $this->hpa = $this->type('HPA', WorkTypeModel::ASKS_FINANCER);
        $this->to = $this->type('TO', WorkTypeModel::ASKS_PARTIES);
        $this->fitness = $this->type('Fitness', null);
    }

    private function party(string $type, string $name): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = $name;
        $party->mobile = '98'.random_int(10000000, 99999999);
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    private function type(string $name, ?string $asks): WorkTypeModel
    {
        $type = new WorkTypeModel;
        $type->name = $name.' '.uniqid();
        $type->asks_for = $asks;
        $type->is_active = 1;
        $type->save();

        return $type;
    }

    /**
     * Every box, typed: what a file cannot be received without, whichever of
     * them its works ask for. A test leaves one empty by sending it as ''.
     */
    private const TYPED = [
        'owner_mobile' => '9431012345',
        'lender_name' => 'State Bank of India',
        'financer_name' => 'Bajaj Finance',
        'seller_name' => 'Ramesh Kumar',
        'seller_mobile' => '9835011111',
        'buyer_name' => 'Sunita Devi',
        'buyer_mobile' => '9835022222',
    ];

    /** One file received through the screen, with these works and boxes. */
    private function receive(array $types, array $boxes = [])
    {
        return $this->actingAs($this->admin)->post(route('workfile.receive'), [
            'received_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'rows' => [[
                'registration_no' => 'br-01-xy-'.random_int(1000, 9999),
                'description' => 'Papers from the dealer',
                'works' => array_map(fn ($type) => ['work_type_id' => $type->id, 'amount' => 1500], $types),
                ...self::TYPED,
                ...$boxes,
            ]],
            'once' => 'sticker-'.uniqid(),
        ]);
    }

    /** A file received before the sticker: none of its details were ever asked. */
    private function receivedBeforeTheSticker(array $types): WorkFileModel
    {
        $this->receive($types)->assertSessionHasNoErrors();
        $file = $this->newest();
        DB::table('work_file')->where('id', $file->id)
            ->update(array_fill_keys(WorkFileModel::DETAIL_COLUMNS, null));

        return $file->fresh();
    }

    private function newest(): WorkFileModel
    {
        return WorkFileModel::where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
    }

    private function sticker(WorkFileModel $file): string
    {
        return $this->actingAs($this->admin)
            ->get(route('workfile.stickers', ['ids' => $file->id]))
            ->assertOk()
            ->getContent();
    }

    // ---- What a work type asks for ------------------------------------------

    public function test_a_work_type_keeps_what_it_asks_for(): void
    {
        $this->actingAs($this->admin)->post(route('worktype.edit', $this->fitness->id), [
            'name' => $this->fitness->name,
            'asks_for' => WorkTypeModel::ASKS_PARTIES,
            'is_active' => 1,
        ])->assertRedirect(route('worktype.index'));

        $this->assertSame(WorkTypeModel::ASKS_PARTIES, $this->fitness->fresh()->asks_for);

        // Nothing more, chosen again, is nothing at all.
        $this->actingAs($this->admin)->post(route('worktype.edit', $this->fitness->id), [
            'name' => $this->fitness->name,
            'asks_for' => '',
            'is_active' => 1,
        ]);

        $this->assertNull($this->fitness->fresh()->asks_for);
    }

    public function test_a_work_type_cannot_ask_for_something_there_is_no_box_for(): void
    {
        $this->actingAs($this->admin)->post(route('worktype.edit', $this->fitness->id), [
            'name' => $this->fitness->name,
            'asks_for' => 'guarantor',
            'is_active' => 1,
        ])->assertSessionHasErrors('asks_for');

        $this->assertNull($this->fitness->fresh()->asks_for);
    }

    // ---- Receive Files --------------------------------------------------------

    public function test_a_file_received_keeps_what_its_works_ask_for(): void
    {
        $this->receive([$this->hpt, $this->to], [
            'owner_mobile' => '9431012345',
            'lender_name' => 'State Bank of India',
            'seller_name' => 'Ramesh Kumar',
            'seller_mobile' => '9835011111',
            'buyer_name' => 'Sunita Devi',
            'buyer_mobile' => '9835022222',
        ])->assertRedirect(route('workfile.index'));

        $file = $this->newest();

        $this->assertSame('9431012345', $file->owner_mobile);
        $this->assertSame('State Bank of India', $file->lender_name);
        $this->assertSame('Ramesh Kumar', $file->seller_name);
        $this->assertSame('9835011111', $file->seller_mobile);
        $this->assertSame('Sunita Devi', $file->buyer_name);
        $this->assertSame('9835022222', $file->buyer_mobile);
        $this->assertNull($file->financer_name);
    }

    /**
     * A box the screen was not showing cannot write one: a file with only an
     * HPT on it has no buyer, whatever a form sent by hand says.
     */
    public function test_a_detail_no_work_asks_for_is_not_kept(): void
    {
        $this->receive([$this->hpt], [
            'lender_name' => 'State Bank of India',
            'financer_name' => 'Bajaj Finance',
            'buyer_name' => 'Somebody',
        ]);

        $file = $this->newest();

        $this->assertSame('State Bank of India', $file->lender_name);
        $this->assertNull($file->financer_name);
        $this->assertNull($file->buyer_name);
    }

    public function test_a_mobile_is_ten_digits(): void
    {
        $this->receive([$this->to], ['owner_mobile' => '94310', 'buyer_mobile' => '98350-22222'])
            ->assertSessionHasErrors(['rows.0.owner_mobile', 'rows.0.buyer_mobile']);

        $this->assertSame(0, WorkFileModel::where('customer_id', $this->customer->id)->count());
    }

    // ---- What a file cannot be received without -------------------------------

    /**
     * Asked for by the owner on 2026-11-19, after the sticker: the details are
     * taken as the file comes in. The owner's mobile on every file, whatever
     * its works.
     */
    public function test_no_file_is_received_without_the_owners_mobile(): void
    {
        $this->receive([$this->fitness], ['owner_mobile' => ''])
            ->assertSessionHasErrors(['rows.0.owner_mobile' => "File 1 needs the owner's mobile."]);

        $this->assertSame(0, WorkFileModel::where('customer_id', $this->customer->id)->count());
    }

    /** And what its works ask for: all of it but the seller's mobile. */
    public function test_no_file_is_received_without_what_its_works_ask_for(): void
    {
        $this->receive([$this->hpt, $this->hpa, $this->to], [
            'lender_name' => '',
            'financer_name' => ' ',
            'seller_name' => '',
            'seller_mobile' => '',
            'buyer_name' => '',
            'buyer_mobile' => '',
        ])
            ->assertSessionHasErrors([
                'rows.0.lender_name' => "File 1 needs the lender's name.",
                'rows.0.financer_name' => "File 1 needs the financer's name.",
                'rows.0.seller_name' => "File 1 needs the seller's name.",
                'rows.0.buyer_name' => "File 1 needs the buyer's name.",
                'rows.0.buyer_mobile' => "File 1 needs the buyer's mobile.",
            ])
            ->assertSessionDoesntHaveErrors(['rows.0.seller_mobile', 'rows.0.owner_mobile']);

        $this->assertSame(0, WorkFileModel::where('customer_id', $this->customer->id)->count());
    }

    /** The seller's mobile was not on the owner's list. */
    public function test_the_sellers_mobile_may_be_left_empty(): void
    {
        $this->receive([$this->to], ['seller_mobile' => ''])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workfile.index'));

        $this->assertNull($this->newest()->seller_mobile);
        $this->assertSame('Sunita Devi', $this->newest()->buyer_name);
    }

    /** A work that asks for nothing more needs nothing more. */
    public function test_a_work_that_asks_for_nothing_needs_only_the_owners_mobile(): void
    {
        $this->receive([$this->fitness], array_fill_keys(['lender_name', 'financer_name', 'seller_name', 'buyer_name', 'buyer_mobile'], ''))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workfile.index'));
    }

    /**
     * Each file of a batch is asked for what its own works need, and named
     * by its place in the batch; one short refuses the batch, as any other
     * mistake in it does.
     */
    public function test_each_file_of_a_batch_is_asked_for_its_own(): void
    {
        $this->actingAs($this->admin)->post(route('workfile.receive'), [
            'received_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'rows' => [
                ['registration_no' => 'BR01ST1001', 'owner_mobile' => '9431012345',
                    'works' => [['work_type_id' => $this->fitness->id, 'amount' => 1500]]],
                // Sent without the box at all, as a form made by hand could be.
                ['registration_no' => 'BR01ST1002', 'owner_mobile' => '9431012346',
                    'works' => [['work_type_id' => $this->hpt->id, 'amount' => 1500]]],
            ],
            'once' => 'sticker-'.uniqid(),
        ])
            ->assertSessionHasErrors(['rows.1.lender_name' => "File 2 needs the lender's name."])
            ->assertSessionDoesntHaveErrors(['rows.0.lender_name', 'rows.0.owner_mobile', 'rows.1.owner_mobile']);

        $this->assertSame(0, WorkFileModel::where('customer_id', $this->customer->id)->count());
    }

    /**
     * Only Receive Files asks. A file from before the sticker has none of
     * these, and Edit File still saves it with them empty.
     */
    public function test_edit_file_does_not_ask_for_them(): void
    {
        $file = $this->receivedBeforeTheSticker([$this->hpt, $this->to]);

        $this->edit($file, array_fill_keys(WorkFileModel::DETAIL_COLUMNS, ''))
            ->assertSessionHasNoErrors();

        $this->assertNull($file->fresh()->owner_mobile);
        $this->assertNull($file->fresh()->lender_name);
    }

    /**
     * The form is sent the rule the post refuses by, and keeps none of its
     * own (asked for on 2026-11-23, the rule having been written three times):
     * what it marks on a file of every kind is exactly what the server will
     * not take that file without.
     */
    public function test_the_receive_form_is_sent_the_rule_the_server_refuses_by(): void
    {
        $sent = $this->actingAs($this->admin)->getJson(route('workfile.receive'))
            ->assertOk()
            ->json('props.requiredOnReceipt');

        $this->assertSame(WorkFileModel::receiptRule(), $sent);

        [$rules] = WorkFileModel::requiredOnReceipt([[
            'works' => array_map(fn ($type) => ['work_type_id' => $type->id], [$this->hpt, $this->hpa, $this->to]),
        ]]);

        $this->assertEqualsCanonicalizing(
            array_map(fn ($detail) => 'rows.0.'.$detail, array_merge($sent['always'], ...array_values($sent['asked']))),
            array_keys($rules)
        );
    }

    /**
     * Whatever the rule is changed to require, the server can say it is
     * missing: every detail has its words, and every detail the rule names is
     * one the form has a box for.
     */
    public function test_every_detail_the_rule_could_require_can_be_named(): void
    {
        $names = (new \ReflectionClassConstant(WorkFileModel::class, 'DETAIL_NAMES'))->getValue();

        $this->assertEqualsCanonicalizing(WorkFileModel::DETAIL_COLUMNS, array_keys($names));

        $rule = WorkFileModel::receiptRule();
        foreach (array_merge($rule['always'], ...array_values($rule['asked'])) as $detail) {
            $this->assertContains($detail, WorkFileModel::DETAIL_COLUMNS);
        }
    }

    public function test_receiving_offers_a_sticker_for_every_file(): void
    {
        $this->receive([$this->hpt])
            ->assertSessionHas('stickers', fn ($offer) => $offer['count'] === 1
                && str_contains($offer['url'], 'ids='.$this->newest()->id)
                && str_contains($offer['url'], 'print=1'));

        $this->actingAs($this->admin)->get(route('workfile.index'))
            ->assertSee('Print sticker');
    }

    // ---- The edit screen ------------------------------------------------------

    private function edit(WorkFileModel $file, array $boxes)
    {
        $item = $file->items()->first();

        return $this->actingAs($this->admin)->post(route('workfile.edit', $file->id), [
            'received_date' => (string) $file->received_date,
            'file_no' => $file->file_no,
            'work_type_id' => $item->work_type_id,
            'registration_no' => $file->registration_no,
            'customer_id' => $file->customer_id,
            'customer_amount' => (float) $file->customer_amount,
            'status' => $file->status,
            'description' => $file->description,
            'drawn' => $file->editFingerprint(),
            'was_status' => $file->status,
            ...$boxes,
        ]);
    }

    public function test_an_edit_corrects_the_details(): void
    {
        $this->receive([$this->hpt], ['owner_mobile' => '9431012345', 'lender_name' => 'State Bank of India']);
        $file = $this->newest();

        $this->edit($file, ['owner_mobile' => '9431099999', 'lender_name' => 'HDFC Bank'])
            ->assertSessionHasNoErrors();

        $file->refresh();
        $this->assertSame('9431099999', $file->owner_mobile);
        $this->assertSame('HDFC Bank', $file->lender_name);
    }

    /**
     * A page drawn before these boxes were on it sends none of them, and a
     * save from it must not blank what somebody since typed.
     */
    public function test_an_edit_without_the_boxes_leaves_them_as_they_are(): void
    {
        $this->receive([$this->hpt], ['owner_mobile' => '9431012345', 'lender_name' => 'State Bank of India']);
        $file = $this->newest();

        $this->edit($file, [])->assertSessionHasNoErrors();

        $file->refresh();
        $this->assertSame('9431012345', $file->owner_mobile);
        $this->assertSame('State Bank of India', $file->lender_name);
    }

    public function test_the_edit_screen_offers_the_files_sticker(): void
    {
        $this->receive([$this->hpt]);
        $file = $this->newest();

        $this->actingAs($this->admin)->get(route('workfile.edit', $file->id))
            ->assertOk()
            // In the screen's props, where the & is written for HTML.
            ->assertSee('admin/file/stickers?ids='.$file->id.'&amp;print=1', false);
    }

    // ---- The sticker ----------------------------------------------------------

    public function test_the_sticker_says_what_the_owner_asked_for(): void
    {
        $this->receive([$this->hpt, $this->hpa, $this->to], [
            'owner_mobile' => '9431012345',
            'lender_name' => 'State Bank of India',
            'financer_name' => 'Bajaj Finance',
            'seller_name' => 'Ramesh Kumar',
            'seller_mobile' => '9835011111',
            'buyer_name' => 'Sunita Devi',
            'buyer_mobile' => '9835022222',
        ]);
        $file = $this->newest();
        $page = $this->sticker($file);

        // 1. The customer, with their mobile.
        $this->assertStringContainsString(e($this->customer->name), $page);
        $this->assertStringContainsString($this->customer->mobile, $page);
        // 2. The vehicle, set out as a plate is read.
        $this->assertStringContainsString(WorkFileModel::plateForReading($file->registration_no), $page);
        $this->assertMatchesRegularExpression('/BR 01 XY \d{4}/', $page);
        // 3. The owner's mobile.
        $this->assertStringContainsString('9431012345', $page);
        // 4 to 6. The lender, the financer, the seller and the buyer.
        foreach (['State Bank of India', 'Bajaj Finance', 'Ramesh Kumar', '9835011111', 'Sunita Devi', '9835022222'] as $said) {
            $this->assertStringContainsString($said, $page);
        }
        // 7. The works.
        foreach ([$this->hpt, $this->hpa, $this->to] as $type) {
            $this->assertStringContainsString(e($type->name), $page);
        }
        // And the file it belongs to.
        $this->assertStringContainsString($file->file_no, $page);
    }

    public function test_the_sticker_is_four_inches_by_six(): void
    {
        $this->receive([$this->fitness]);

        $this->assertStringContainsString('size: 4in 6in', $this->sticker($this->newest()));
    }

    /**
     * Asked for and not typed: a line, to write it in by hand. Since every
     * file comes in with the rest, that is the seller's mobile, which may be
     * left empty, and anything on a file received before the sticker.
     */
    public function test_a_detail_not_yet_typed_leaves_a_line(): void
    {
        $this->receive([$this->to], ['seller_mobile' => '']);
        $this->assertSame(1, substr_count($this->sticker($this->newest()), 'class="st-blank"'), "the seller's mobile");

        $page = $this->sticker($this->receivedBeforeTheSticker([$this->hpt]));

        $this->assertStringContainsString('Lender &middot; '.e($this->hpt->name), $page);
        $this->assertSame(2, substr_count($page, 'class="st-blank"'), "the owner's mobile and the lender");
    }

    public function test_a_detail_no_work_asks_for_is_not_on_the_sticker(): void
    {
        $this->receive([$this->fitness]);
        $page = $this->sticker($this->newest());

        $this->assertStringNotContainsString('Lender', $page);
        $this->assertStringNotContainsString('Financer', $page);
        $this->assertStringNotContainsString('Seller', $page);
    }

    /** A cancelled work asks for nothing and is not one of the file's works. */
    public function test_a_cancelled_work_is_left_off(): void
    {
        $this->receive([$this->fitness, $this->to]);
        $file = $this->newest();
        $file->items()->where('work_type_id', $this->to->id)->update(['status' => WorkFileModel::CANCELLED]);

        $page = $this->sticker($file);

        $this->assertStringNotContainsString(e($this->to->name), $page);
        $this->assertStringNotContainsString('Seller', $page);
    }

    /** The owner's rule: a customer is never shown a vendor's name, and the folder goes back to them. */
    public function test_the_vendor_is_never_on_it(): void
    {
        $this->receive([$this->hpt]);
        $file = $this->newest();
        WorkFileItemModel::where('work_file_id', $file->id)->update(['vendor_id' => $this->vendor->id, 'status' => WorkFileModel::DISPATCHED]);
        DB::table('work_file')->where('id', $file->id)->update(['vendor_id' => $this->vendor->id]);

        $this->assertStringNotContainsString($this->vendor->name, $this->sticker($file->fresh()));
    }

    public function test_several_files_print_a_page_each(): void
    {
        $this->receive([$this->hpt]);
        $first = $this->newest();
        $this->receive([$this->fitness]);
        $second = $this->newest();

        $page = $this->actingAs($this->admin)
            ->get(route('workfile.stickers', ['ids' => $first->id.','.$second->id]))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($page, '<section class="sticker"'));
        $this->assertLessThan(strpos($page, $second->file_no), strpos($page, $first->file_no));
    }

    public function test_no_files_is_no_page(): void
    {
        $this->actingAs($this->admin)->get(route('workfile.stickers'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('workfile.stickers', ['ids' => '0,abc']))->assertNotFound();
    }

    public function test_only_the_office_prints_them(): void
    {
        $this->receive([$this->hpt]);
        $file = $this->newest();

        auth()->logout();

        $this->get(route('workfile.stickers', ['ids' => $file->id]))->assertRedirect();
    }

    // ---- The migration's starting settings ------------------------------------

    /**
     * Types named exactly HPT, HPA and TR (the office's transfer; TO too)
     * start asking for theirs; one already set, or named anything else, is
     * left alone; and it can run again.
     */
    public function test_the_migration_starts_hpt_hpa_and_tr_off_and_runs_again_safely(): void
    {
        $plain = function (string $name) {
            DB::table('work_type')->whereRaw('UPPER(TRIM(name)) = ?', [strtoupper(trim($name))])->delete();

            return DB::table('work_type')->insertGetId(['name' => $name, 'is_active' => 1]);
        };

        $hpt = $plain('HPT');
        $hpa = $plain('HPA');
        $tr = $plain(' tr ');
        $to = $plain('TO');
        $other = $plain('Duplicate RC');
        DB::table('work_type')->where('id', $to)->update(['asks_for' => WorkTypeModel::ASKS_LENDER]);

        $migration = require database_path('migrations/2026_11_19_000100_let_a_file_carry_its_sticker_details.php');
        $migration->up();
        $migration->up();

        $asks = fn (int $id) => DB::table('work_type')->where('id', $id)->value('asks_for');
        $this->assertSame(WorkTypeModel::ASKS_LENDER, $asks($hpt));
        $this->assertSame(WorkTypeModel::ASKS_FINANCER, $asks($hpa));
        $this->assertSame(WorkTypeModel::ASKS_PARTIES, $asks($tr), 'TR is the office\'s transfer, whatever its case and spaces');
        $this->assertSame(WorkTypeModel::ASKS_LENDER, $asks($to), 'one already chosen is kept');
        $this->assertNull($asks($other));
    }
}
