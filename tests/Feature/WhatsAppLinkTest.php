<?php

namespace Tests\Feature;

use App\Models\PartyModel;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The WhatsApp links on a statement's header and in the ledger lists.
 *
 * They were "https://wa.me/91" and whatever number was saved. The form accepts
 * any ten digits, so a landline became a link to WhatsApp's "not on WhatsApp"
 * page. Now they follow the rule the reminder buttons do (App\Support\WhatsApp),
 * and a number that cannot have a chat is shown without a link to one.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class WhatsAppLinkTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = new User;
        $this->admin->name = 'WhatsApp Link Admin';
        $this->admin->email = 'wa-link-'.uniqid().'@example.com';
        $this->admin->password = Hash::make('password-for-tests');
        $this->admin->user_type = 1;
        $this->admin->save();
    }

    private function party(string $mobile, ?string $whatsapp = null, string $type = 'customer'): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = 'Linked '.uniqid();
        $party->mobile = $mobile;
        $party->whatsapp = $whatsapp;
        $party->is_active = 1;
        $party->save();

        return $party;
    }

    /** A number no real party holds, so the test cannot collide with one. */
    private function mobile(): string
    {
        return '97'.random_int(10000000, 99999999);
    }

    private function statement(PartyModel $party): string
    {
        return $this->actingAs($this->admin)
            ->get(route('party.statement', $party->id))->assertOk()->getContent();
    }

    private function listRow(PartyModel $party): array
    {
        return collect($this->actingAs($this->admin)
            ->getJson(route('party.index', $party->party_type))->assertOk()->json('props.rows'))
            ->firstWhere('id', $party->id);
    }

    // ------------------------------------------------------------ the statement

    public function test_the_statement_opens_a_chat_on_a_mobile(): void
    {
        $mobile = $this->mobile();

        $this->assertStringContainsString('href="https://wa.me/91'.$mobile.'"', $this->statement($this->party($mobile)));
    }

    public function test_a_separate_whatsapp_number_is_the_one_it_opens(): void
    {
        $whatsapp = $this->mobile();

        $html = $this->statement($this->party($this->mobile(), $whatsapp));

        $this->assertStringContainsString('href="https://wa.me/91'.$whatsapp.'"', $html);
    }

    /** Ten digits the form accepts, and no chat to be had with them. */
    public function test_a_landline_gets_no_whatsapp_link_but_keeps_its_call_link(): void
    {
        $landline = '0612'.random_int(100000, 999999);

        $html = $this->statement($this->party($landline));

        $this->assertStringNotContainsString('wa.me/', $html);
        $this->assertStringContainsString('href="tel:'.$landline.'"', $html);
    }

    // ------------------------------------------------------------ the lists

    public function test_the_ledger_list_links_a_mobile_to_its_chat(): void
    {
        $mobile = $this->mobile();

        $row = $this->listRow($this->party($mobile));

        $this->assertSame('https://wa.me/91'.$mobile, $row['whatsapp_url']);
    }

    public function test_the_ledger_list_shows_a_landline_without_a_link(): void
    {
        $landline = '0612'.random_int(100000, 999999);

        $row = $this->listRow($this->party($landline, null, 'vendor'));

        $this->assertNull($row['whatsapp_url']);
        // Still shown, just not as a link to a chat that cannot exist.
        $this->assertSame($landline, $row['whatsapp']);
    }
}
