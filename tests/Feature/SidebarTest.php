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
            'give to vendor' => ['admin/file/assign', 'Give to Vendor'],
            'return from vendor' => ['admin/file/vendor-return', 'Return from Vendor'],
            'return to customer' => ['admin/file/customer-return', 'Return to Customer'],
            'update status' => ['admin/file/status', 'Update Status'],
            'all files' => ['admin/files', 'All Work Files'],
            'work types' => ['admin/work-types', 'Work Types'],
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
}
