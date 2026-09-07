<?php

use App\Http\Controllers\Api\WorkFileApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkFileController;
use App\Http\Controllers\WorkTypeController;
use Illuminate\Support\Facades\Route;

/*
 * The front door is the customer portal.
 *
 * It was the client portal at /user, which still works and is still where the
 * client logins go — only what a bare acinfo.in lands on has changed. Customers
 * are the larger audience and the ones being sent here, so they get the address
 * that is easy to say over a phone.
 */
Route::get('/', function () {
    return redirect('/customer');
});

Route::get('/admin', [AuthController::class, 'login']);

Route::post('admin-login', [AuthController::class, 'authlogin'])->middleware('throttle:10,1');

Route::post('admin/logout', [AuthController::class, 'logout'])->name('adminlogout');

Route::group(['middleware' => 'admin'], function () {
    Route::get('admin/dashboard', [AuthController::class, 'dashboard']);

    Route::match(['get', 'post'], 'admin/add-clients', [AuthController::class, 'client'])->name('addclients');

    Route::get('admin/view-clients', [AuthController::class, 'viewclient'])->name('viewclient');

    Route::match(['get', 'post'], 'admin/client/password/{id}', [AuthController::class, 'clientpassword'])->name('clientpassword');

    // The signed-in admin changing their own. No {id}: this is never about
    // somebody else's account, and one taken from the URL would be.
    Route::match(['get', 'post'], 'admin/password', [AuthController::class, 'password'])
        ->middleware('throttle:10,1')
        ->name('adminpassword');

    Route::match(['get', 'post'], 'admin/receipt', [AuthController::class, 'paymentreceipt'])->name('receipt');

    Route::match(['get', 'post'], 'admin/payment', [AuthController::class, 'payment'])->name('payment');

    Route::get('admin/client/statement/{id}', [AuthController::class, 'clientstatement'])->name('clientstatement');

});

/*
 * Vendor & Customer ledgers. Kept in their own group so the whole feature can be
 * added or removed without touching the client ledger routes above.
 *
 * The literal segment always comes second (party/add/{type}, party/edit/{id}),
 * so no two of these patterns can ever collide.
 */
Route::group(['middleware' => 'admin'], function () {
    Route::get('admin/parties/{type}', [PartyController::class, 'index'])->name('party.index');

    Route::match(['get', 'post'], 'admin/party/add/{type}', [PartyController::class, 'create'])->name('party.create');

    Route::match(['get', 'post'], 'admin/party/entry/{type}', [PartyController::class, 'entry'])->name('party.entry');

    Route::match(['get', 'post'], 'admin/party/edit/{id}', [PartyController::class, 'edit'])->name('party.edit');

    Route::get('admin/party/statement/{id}', [PartyController::class, 'statement'])->name('party.statement');

    // Issuing a customer their portal login. Customers only; the controller
    // refuses a vendor id outright.
    Route::match(['get', 'post'], 'admin/party/password/{id}', [PartyController::class, 'password'])->name('party.password');
});

/*
 * Work files. Each file books itself into the party ledgers above, so this group
 * depends on that one — but nothing outside these two groups depends on either.
 */
Route::group(['middleware' => 'admin'], function () {
    Route::match(['get', 'post'], 'admin/work-types', [WorkTypeController::class, 'index'])->name('worktype.index');

    /*
     * Above the {id} route, which would otherwise swallow it: 'delete' reads
     * as an id and finds no work type by that name.
     */
    Route::post('admin/work-types/{id}/delete', [WorkTypeController::class, 'destroy'])->name('worktype.delete');

    Route::match(['get', 'post'], 'admin/work-types/{id}', [WorkTypeController::class, 'index'])->name('worktype.edit');

    Route::get('admin/files', [WorkFileController::class, 'index'])->name('workfile.index');

    // Work that is through, on a screen that says when and shows the evidence.
    Route::get('admin/files/approved', [WorkFileController::class, 'approved'])->name('workfile.approved');

    // The three moments in a file's life, each on its own screen.
    Route::match(['get', 'post'], 'admin/file/receive', [WorkFileController::class, 'receive'])->name('workfile.receive');

    Route::match(['get', 'post'], 'admin/file/assign', [WorkFileController::class, 'assign'])->name('workfile.assign');

    Route::match(['get', 'post'], 'admin/file/vendor-return', [WorkFileController::class, 'vendorReturn'])->name('workfile.vendorreturn');

    Route::match(['get', 'post'], 'admin/file/customer-return', [WorkFileController::class, 'customerReturn'])->name('workfile.customerreturn');

    Route::match(['get', 'post'], 'admin/file/status', [WorkFileController::class, 'status'])->name('workfile.status');

    Route::match(['get', 'post'], 'admin/file/edit/{id}', [WorkFileController::class, 'edit'])->name('workfile.edit');

    // Read-only reporting.
    Route::get('admin/reports/files', [ReportController::class, 'files'])->name('report.files');

    // What the work earned, cut by month, year, work type, vendor or customer.
    Route::get('admin/reports/profit', [ReportController::class, 'profit'])->name('report.profit');

    /*
     * JSON for the browser-side screens. Inside the admin group on purpose: a
     * vehicle's history and prices are not public just because they are JSON.
     */
    Route::get('admin/api/work-files/history', [WorkFileApiController::class, 'history'])->name('api.workfile.history');

    // What this customer has been charged for each work before.
    Route::get('admin/api/work-files/customer-rates', [WorkFileApiController::class, 'customerRates'])->name('api.workfile.customerrates');
});

Route::get('/user', [UserController::class, 'userlogin']);
Route::post('user-login', [UserController::class, 'authuserlogin'])->middleware('throttle:10,1');
Route::post('user/logout', [UserController::class, 'logout'])->name('userlogout');

Route::group(['middleware' => 'userAuth'], function () {
    Route::get('user/dashboard', [UserController::class, 'userdashboard'])->name('userdashboard');
    Route::get('user/client/statement', [UserController::class, 'userstatement'])->name('userstatement');
});

/*
 * The customer portal.
 *
 * A separate area from /user on purpose. That one signs in against the client
 * table and keeps its id in session('userid'); this one signs in against party
 * and keeps its id in session('customer_id'). The two must not share a key —
 * client #5 and customer #5 are different people with different ledgers, and a
 * page that resolves the wrong one shows somebody else's money.
 */
Route::get('/customer', [CustomerPortalController::class, 'login'])->name('customer.login');
Route::post('customer-login', [CustomerPortalController::class, 'authenticate'])
    ->middleware('throttle:10,1')
    ->name('customer.authenticate');
Route::post('customer/logout', [CustomerPortalController::class, 'logout'])->name('customer.logout');

Route::group(['middleware' => 'customerAuth'], function () {
    Route::get('customer/dashboard', [CustomerPortalController::class, 'dashboard'])->name('customer.dashboard');

    // No {id} on either. The party is whoever is signed in, and a statement or
    // a file list that took one from the URL would be one anybody could ask for.
    Route::get('customer/statement', [CustomerPortalController::class, 'statement'])->name('customer.statement');

    Route::get('customer/files', [CustomerPortalController::class, 'files'])->name('customer.files');

    // Their own, and only their own. Throttled because the current-password
    // check on it is a password check like any other.
    Route::match(['get', 'post'], 'customer/password', [CustomerPortalController::class, 'password'])
        ->middleware('throttle:10,1')
        ->name('customer.password');

    /*
     * One file, and its approval image. Both take an id, and both resolve it
     * through the signed-in customer's own scope — a file belonging to somebody
     * else is not found rather than found and then refused.
     *
     * The approval is served by the application rather than linked at its path
     * under public/, where it would have no authentication at all.
     */
    Route::get('customer/file/{id}', [CustomerPortalController::class, 'file'])
        ->whereNumber('id')
        ->name('customer.file');

    Route::get('customer/file/{id}/approval/{item?}', [CustomerPortalController::class, 'approval'])
        ->whereNumber('id')
        ->whereNumber('item')
        ->name('customer.file.approval');
});
