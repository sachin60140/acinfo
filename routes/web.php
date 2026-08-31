<?php

use App\Http\Controllers\Api\WorkFileApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkFileController;
use App\Http\Controllers\WorkTypeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/user');
});

Route::get('/admin', [AuthController::class, 'login']);

Route::post('admin-login', [AuthController::class, 'authlogin'])->middleware('throttle:10,1');

Route::post('admin/logout', [AuthController::class, 'logout'])->name('adminlogout');

Route::group(['middleware' => 'admin'], function () {
    Route::get('admin/dashboard', [AuthController::class, 'dashboard']);

    Route::match(['get', 'post'], 'admin/add-clients', [AuthController::class, 'client'])->name('addclients');

    Route::get('admin/view-clients', [AuthController::class, 'viewclient'])->name('viewclient');

    Route::match(['get', 'post'], 'admin/client/password/{id}', [AuthController::class, 'clientpassword'])->name('clientpassword');

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
});

Route::get('/user', [UserController::class, 'userlogin']);
Route::post('user-login', [UserController::class, 'authuserlogin'])->middleware('throttle:10,1');
Route::post('user/logout', [UserController::class, 'logout'])->name('userlogout');

Route::group(['middleware' => 'userAuth'], function () {
    Route::get('user/dashboard', [UserController::class, 'userdashboard'])->name('userdashboard');
    Route::get('user/client/statement', [UserController::class, 'userstatement'])->name('userstatement');
});
