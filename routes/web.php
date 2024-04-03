<?php

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


use Illuminate\Support\Facades\Route;

Route::redirect('/', 'menu/mygremium')->name('home');

Route::get('auth/login', [\App\Http\Controllers\AuthController::class, 'login'])->name('login');
Route::get('auth/logout', [\App\Http\Controllers\AuthController::class, 'logout'])->name('logout');
Route::get('auth/callback', [\App\Http\Controllers\AuthController::class, 'callback'])->name('login.callback');

Route::middleware(['auth'])->group(function(){

    Route::get('plan', [\App\Http\Controllers\BudgetPlanController::class, 'index'])->name('budget-plan.index');
    Route::get('plan/create', [\App\Http\Controllers\BudgetPlanController::class, 'create'])->name('budget-plan.create');
    Route::get('plan/{plan_id}', [\App\Http\Controllers\BudgetPlanController::class, 'show'])->name('budget-plan.show');
    //Route::get('plan/{plan_id}/edit', \App\Http\Livewire\BudgetPlanLivewire::class)->name('budget-plan.edit');
});

// guest routes
Route::get('about', function (){
    return redirect(config('app.about_url'));
})->name('about');

Route::get('privacy', function (){
    return redirect(config('app.privacy_url'));
})->name('privacy');

Route::get('terms', function (){
    return redirect(config('app.terms_url'));
})->name('terms');

Route::get('git-repo', function (){
    return redirect(config('app.git-repo'));
})->name('git-repo');

if (App::isLocal()){
    Route::get('dev/groups', [\App\Http\Controllers\Dev::class, 'groups']);
}

