<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
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

Route::get('/user', [UserController::class, 'userlogin']);
Route::post('user-login', [UserController::class, 'authuserlogin'])->middleware('throttle:10,1');
Route::post('user/logout', [UserController::class, 'logout'])->name('userlogout');

Route::group(['middleware' => 'userAuth'], function () {
    Route::get('user/dashboard', [UserController::class, 'userdashboard'])->name('userdashboard');
    Route::get('user/client/statement', [UserController::class, 'userstatement'])->name('userstatement');
});
