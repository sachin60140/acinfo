<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;

Route::get('/', function () {
    return redirect('/admin'); 
});

Route::get('/admin',[AuthController::class, 'login']);

Route::get('pass',[AuthController::class, 'pass']);

Route::post('admin-login',[AuthController::class, 'authlogin']);

Route::get('admin/logout',[AuthController::class, 'logout']);


Route::group(['middleware'=>'admin'],function()
{
    Route::get('admin/dashboard',[AuthController::class, 'dashboard']);
    
    Route::match(["get","post"],'admin/add-clients',[AuthController::class, 'client'])->name('addclients');

    Route::get('admin/view-clients',[AuthController::class, 'viewclient'])->name('viewclient');

    Route::match(["get","post"],'admin/receipt',[AuthController::class, 'paymentreceipt'])->name('receipt');

    Route::match(["get","post"],'admin/payment',[AuthController::class, 'payment'])->name('payment');

    Route::get('admin/client/statement/{id}',[AuthController::class, 'clientstatement'])->name('clientstatement');
 
});

Route::get('/user',[UserController::class, 'userlogin']);
Route::post('user-login',[UserController::class, 'authuserlogin']);
Route::get('user/logout',[UserController::class, 'logout']);

Route::group(['middleware'=>'userAuth'],function()
{
    Route::get('user/dashboard',[UserController::class, 'userdashboard'])->name('userdashboard');
    Route::get('user/client/statement',[UserController::class, 'userstatement'])->name('userstatement');
});