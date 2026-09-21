<?php

namespace Tests\Feature;

use App\Models\ClientModel;
use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\User;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sidebar highlighting.
 *
 * Worth its own suite because the failure is silent: the template ships no
 * .active rule — its highlighted look is the absence of .collapsed — so markup
 * emitting "nav-link collapsed active" looks exactly like an inactive item and
 * nothing about the page appears broken.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class SidebarTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Sidebar Admin';
        $user->email = 'sidebar-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    /**
     * Every anchor in the sidebar, with whether it is marked active.
     *
     * @return array<int, array{label: string, active: bool}>
     */
    private function menu(string $html): array
    {
        $sidebar = preg_match('#<aside id="sidebar".*?</aside>#s', $html, $match) ? $match[0] : '';

        preg_match_all('#<a class="(nav-link[^"]*)"[^>]*>.*?<span>(.*?)</span>#s', $sidebar, $links, PREG_SET_ORDER);

        return array_map(fn ($link) => [
            'label' => trim($link[2]),
            // The template's own mechanism: highlighted means not collapsed.
            'active' => str_contains($link[1], 'active') && ! str_contains($link[1], 'collapsed'),
        ], $links);
    }

    /**
     * @return array<string, array{string, string}>  url => expected menu label
     */
    public static function adminScreens(): array
    {
        return [
            'dashboard' => ['admin/dashboard', 'Dashboard'],
            'add client' => ['admin/add-clients', 'Add Client Ledger'],
            'view clients' => ['admin/view-clients', 'View Client'],
            'receipt' => ['admin/receipt', 'Receipt'],
            'payment' => ['admin/payment', 'Payment'],
            'customers' => ['admin/parties/customer', 'Customer Ledger'],
            'add customer' => ['admin/party/add/customer', 'Customer Ledger'],
            'customer entry' => ['admin/party/entry/customer', 'Customer Ledger'],
            'vendors' => ['admin/parties/vendor', 'Vendor Ledger'],
            'add vendor' => ['admin/party/add/vendor', 'Vendor Ledger'],
            'vendor entry' => ['admin/party/entry/vendor', 'Vendor Ledger'],
            'receive files' => ['admin/file/receive', 'Receive Files'],
            'paper audit' => ['admin/file/audit', 'Paper Audit'],
            'give to vendor' => ['admin/file/assign', 'Give to Vendor'],
            'in-house work' => ['admin/file/in-house', 'In-house Work'],
            'return from vendor' => ['admin/file/vendor-return', 'Return from Vendor'],
            'return to customer' => ['admin/file/customer-return', 'Return to Customer'],
            'hand over papers' => ['admin/file/handover', 'Hand Over Papers'],
            'update status' => ['admin/file/status', 'Update Status'],
            'approved files' => ['admin/files/approved', 'Approved Files'],
            'all files' => ['admin/files', 'All Work Files'],
            'work types' => ['admin/work-types', 'Work Types'],
            'paper types' => ['admin/paper-types', 'Paper Types'],
            'profit report' => ['admin/reports/profit', 'Profit Report'],
            'work report' => ['admin/reports/files', 'Work Report'],
        ];
    }

    #[DataProvider('adminScreens')]
    public function test_each_screen_highlights_exactly_one_menu_item(string $url, string $expected): void
    {
        $this->actingAs($this->admin());

        $menu = $this->menu($this->get($url)->assertOk()->getContent());

        $this->assertNotEmpty($menu, 'the sidebar rendered no links');

        $active = array_values(array_filter($menu, fn ($item) => $item['active']));

        $this->assertCount(1, $active, $url.' highlighted '.count($active).' items, expected exactly 1');
        $this->assertSame($expected, $active[0]['label'], $url.' highlighted the wrong item');
    }

    /**
     * Screens reached from a list rather than the menu still belong to the item
     * they were opened from. Each of these needs a record to exist first, so they
     * are built here rather than in the data provider.
     */
    public function test_detail_screens_highlight_the_menu_they_belong_to(): void
    {
        $this->actingAs($this->admin());

        $customer = $this->party('customer', '9000000501');
        $vendor = $this->party('vendor', '9000000502');

        $type = new WorkTypeModel;
        $type->name = 'Sidebar Work '.uniqid();
        $type->is_active = 1;
        $type->save();

        $file = new WorkFileModel;
        $file->file_no = 'F-SIDE-'.uniqid();
        $file->received_date = now()->toDateString();
        $file->work_type_id = $type->id;
        $file->customer_id = $customer->id;
        $file->customer_amount = 1000;
        $file->status = 'in_office';
        $file->save();

        $client = ClientModel::first();

        $cases = [
            // A party edit/statement URL carries an id, not a type, so the menu
            // has to look the type up. This is what segment matching got wrong.
            'admin/party/edit/'.$customer->id => 'Customer Ledger',
            'admin/party/statement/'.$customer->id => 'Customer Ledger',
            'admin/party/edit/'.$vendor->id => 'Vendor Ledger',
            'admin/party/statement/'.$vendor->id => 'Vendor Ledger',
            'admin/file/edit/'.$file->id => 'All Work Files',
            'admin/work-types/'.$type->id => 'Work Types',
        ];

        if ($client) {
            $cases['admin/client/statement/'.$client->id] = 'View Client';
            $cases['admin/client/password/'.$client->id] = 'View Client';
        }

        foreach ($cases as $url => $expected) {
            $active = array_values(array_filter($this->menu($this->get($url)->assertOk()->getContent()), fn ($item) => $item['active']));

            $this->assertCount(1, $active, $url.' highlighted '.count($active).' items');
            $this->assertSame($expected, $active[0]['label'], $url.' highlighted the wrong item');
        }
    }

    public function test_the_client_facing_sidebar_highlights_too(): void
    {
        $client = ClientModel::first();

        if (! $client) {
            $this->markTestSkipped('no client to sign in as');
        }

        // The keys UserAuthMiddleware and UserController actually read.
        session(['userid' => $client->id, 'username' => $client->name]);

        foreach (['user/dashboard' => 'Dashboard', 'user/client/statement' => 'Ledger'] as $url => $expected) {
            $active = array_values(array_filter(
                $this->menu($this->get($url)->assertOk()->getContent()),
                fn ($item) => $item['active']
            ));

            $this->assertCount(1, $active, $url.' highlighted '.count($active).' items');
            $this->assertSame($expected, $active[0]['label']);
        }
    }

    private function party(string $type, string $mobile): PartyModel
    {
        $party = new PartyModel;
        $party->party_type = $type;
        $party->name = ucfirst($type).' '.$mobile;
        $party->mobile = $mobile;
        $party->is_active = 1;
        $party->save();

        // A balance so the statement screen has something to render.
        $entry = new PartyLedgerModel;
        $entry->party_id = $party->id;
        $entry->txn_date = now()->toDateString();
        $entry->entry_type = 'debit';
        $entry->amount = 500;
        $entry->particular = 'Sidebar test entry';
        $entry->save();

        return $party;
    }
    /*
     * ---- Rolling the sections up --------------------------------------------
     *
     * The state is rendered by the server rather than applied by script
     * afterwards, so it is testable here — and has to be, because the failure is
     * a menu that springs open again on every page and nothing else.
     */

    /*
     * The four headings that start open, and the keys their state is stored
     * under. Setup is not among them: it starts shut, so the cookie means the
     * opposite thing about it and it is tested on its own below.
     */
    public static function menuSections(): array
    {
        return [
            'client ledger' => ['client-ledger', 'Client Ledger'],
            'vendor and customer' => ['vendor-customer', 'Vendor &amp; Customer'],
            'work files' => ['work-files', 'Work Files'],
            'reports' => ['reports', 'Reports'],
        ];
    }

    #[DataProvider('menuSections')]
    public function test_every_section_can_be_rolled_up(string $key, string $label): void
    {
        $body = $this->actingAs($this->admin())->get('/admin/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString($label, $body);

        // A button, so it is reachable by keyboard — and one that says both what
        // it controls and whether that is currently open.
        $this->assertMatchesRegularExpression(
            '/<button[^>]*aria-expanded="true"[^>]*aria-controls="nav-group-'.preg_quote($key, '/').'"/s',
            $body,
            "the $label heading is not a toggle"
        );

        $this->assertStringContainsString('id="nav-group-'.$key.'"', $body);
    }

    #[DataProvider('menuSections')]
    public function test_a_section_the_reader_shut_arrives_shut(string $key, string $label): void
    {
        $body = $this->actingAs($this->admin())
            ->withUnencryptedCookie('nav_collapsed', $key)
            ->get('/admin/dashboard')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/aria-expanded="false"[^>]*aria-controls="nav-group-'.preg_quote($key, '/').'"/s',
            $body,
            "$label came back open"
        );

        // hidden on the list itself, so a shut section is shut for a screen
        // reader and for find-in-page too, not only to the eye.
        $this->assertMatchesRegularExpression(
            '/id="nav-group-'.preg_quote($key, '/').'"\s+hidden/s',
            $body
        );
    }

    public function test_shutting_one_section_leaves_the_others_alone(): void
    {
        $body = $this->actingAs($this->admin())
            ->withUnencryptedCookie('nav_collapsed', 'work-files')
            ->get('/admin/dashboard')->assertOk()->getContent();

        foreach (['client-ledger', 'vendor-customer', 'reports'] as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/id="nav-group-'.preg_quote($key, '/').'"\s+hidden/s',
                $body,
                "$key was shut too"
            );
        }
    }

    /**
     * A shut section still says the reader is inside it.
     *
     * Rolling up the section you are working in otherwise leaves nothing on
     * screen saying which part of the application you are in: the highlighted
     * item that said so is inside the part that was just hidden.
     */
    public function test_a_shut_section_still_says_the_page_is_in_there(): void
    {
        $body = $this->actingAs($this->admin())
            ->withUnencryptedCookie('nav_collapsed', 'reports')
            ->get(route('report.profit'))->assertOk()->getContent();

        // On the section holding the page, and on no other.
        $this->assertSame(1, substr_count($body, 'nav-heading--current'));

        $this->assertMatchesRegularExpression(
            '/nav-heading--current[^>]*aria-controls="nav-group-reports"/s',
            $body
        );
    }

    /*
     * ---- Setup, which starts shut ------------------------------------------
     *
     * It holds the three lists the office writes once and never opens again —
     * work types, expense types, paper types — which sat among the screens used
     * every hour, in a section carrying twelve of the menu's twenty-four
     * entries.
     *
     * What the cookie holds is every section that is not the way it starts, so
     * the four older sections are in it once they are shut and Setup is in it
     * once it is opened. One cookie, read against each section's own default.
     */

    /** The links inside one section, and nothing from any other. */
    private function sectionLinks(string $body, string $key): string
    {
        $this->assertMatchesRegularExpression('/id="nav-group-'.preg_quote($key, '/').'"/s', $body, "no $key section");

        preg_match('/id="nav-group-'.preg_quote($key, '/').'"[^>]*>(.*?)<\/ul>/s', $body, $found);

        return $found[1] ?? '';
    }

    public function test_setup_arrives_shut_for_a_reader_who_has_never_touched_it(): void
    {
        $body = $this->actingAs($this->admin())->get('/admin/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('Setup', $body);

        $this->assertMatchesRegularExpression(
            '/aria-expanded="false"[^>]*aria-controls="nav-group-setup"/s',
            $body,
            'Setup came back open to somebody who never opened it'
        );

        $this->assertMatchesRegularExpression('/id="nav-group-setup"\s+hidden/s', $body);
    }

    /** The same cookie, meaning the opposite thing, because Setup starts shut. */
    public function test_setup_arrives_open_for_a_reader_who_opened_it(): void
    {
        $body = $this->actingAs($this->admin())
            ->withUnencryptedCookie('nav_collapsed', 'setup')
            ->get('/admin/dashboard')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/aria-expanded="true"[^>]*aria-controls="nav-group-setup"/s',
            $body,
            'Setup stayed shut for somebody who opened it'
        );

        $this->assertDoesNotMatchRegularExpression('/id="nav-group-setup"\s+hidden/s', $body);
    }

    /** And opening Setup does not shut anything else that was left alone. */
    public function test_opening_setup_leaves_the_other_sections_open(): void
    {
        $body = $this->actingAs($this->admin())
            ->withUnencryptedCookie('nav_collapsed', 'setup')
            ->get('/admin/dashboard')->assertOk()->getContent();

        foreach (['client-ledger', 'vendor-customer', 'work-files', 'reports'] as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/id="nav-group-'.preg_quote($key, '/').'"\s+hidden/s',
                $body,
                "$key was shut too"
            );
        }
    }

    public function test_the_lists_the_office_writes_once_left_the_work_screens(): void
    {
        $body = $this->actingAs($this->admin())->get('/admin/dashboard')->assertOk()->getContent();

        $setup = $this->sectionLinks($body, 'setup');
        $work = $this->sectionLinks($body, 'work-files');

        foreach ([route('worktype.index'), route('expensetype.index'), route('papertype.index')] as $href) {
            $this->assertStringContainsString($href, $setup, "$href is not under Setup");
            $this->assertStringNotContainsString($href, $work, "$href is still among the work screens");
        }

        // And the work of the day stayed where it was.
        $this->assertStringContainsString(route('workfile.assign'), $work);
        $this->assertStringContainsString(route('workfile.index'), $work);
    }

    /** Shut, it still says the reader is inside it — the same as any section. */
    public function test_setup_says_the_page_is_in_there_while_it_is_shut(): void
    {
        $body = $this->actingAs($this->admin())
            ->get(route('worktype.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($body, 'nav-heading--current'));

        $this->assertMatchesRegularExpression(
            '/nav-heading--current[^>]*aria-controls="nav-group-setup"/s',
            $body
        );
    }

    /**
     * Nothing was dropped when the four sections became one list.
     *
     * They were written out four times over before, and a rewrite that loses an
     * item leaves a screen reachable only by typing its address.
     */
    public function test_every_screen_is_still_on_the_menu(): void
    {
        $body = $this->actingAs($this->admin())->get('/admin/dashboard')->assertOk()->getContent();

        foreach ([
            'Dashboard', 'Add Client Ledger', 'View Client', 'Receipt', 'Payment',
            'Vendor Ledger', 'Customer Ledger',
            'Receive Files', 'Paper Audit', 'Give to Vendor', 'In-house Work', 'Return from Vendor', 'Return to Customer', 'Hand Over Papers',
            'Update Status', 'Approved Files', 'All Work Files', 'Work Types', 'Expense Types', 'Paper Types',
            'Profit Report', 'Work Report', 'Expense Report',
        ] as $item) {
            $this->assertStringContainsString('<span>'.$item.'</span>', $body, "$item is not on the menu");
        }
    }
}