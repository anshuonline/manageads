<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::any('/playlist-api.php', [\App\Http\Controllers\LegacyPlaylistController::class, 'handle']);
Route::any('/user-api.php', [\App\Http\Controllers\LegacyUserController::class, 'handle']);
Route::any('/api.php', [\App\Http\Controllers\LegacyAdController::class, 'handle']);
Route::any('/managegt-api.php', [\App\Http\Controllers\LegacyAdminController::class, 'handle']);
