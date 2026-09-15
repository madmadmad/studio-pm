<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Web\BookkeepingPageController;
use App\Http\Controllers\Web\ClientPageController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\InvoicePageController;
use App\Http\Controllers\Web\ProjectPageController;
use App\Http\Controllers\Web\ProposalPageController;
use App\Http\Controllers\Web\PublicProposalController;
use App\Http\Controllers\Web\ServicePageController;
use App\Http\Controllers\Web\TimePageController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/p/{token}', [PublicProposalController::class, 'show'])->name('proposals.public');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/clients', [ClientPageController::class, 'index'])->name('clients.index');
    Route::get('/clients/{company}', [ClientPageController::class, 'show'])->name('clients.show');

    Route::get('/projects', [ProjectPageController::class, 'index'])->name('projects.index');

    Route::get('/time-entries', [TimePageController::class, 'index'])->name('time.index');
    Route::get('/timesheets', [TimePageController::class, 'weekly'])->name('timesheets.index');

    Route::get('/invoices', [InvoicePageController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoicePageController::class, 'show'])->name('invoices.show');

    Route::get('/proposals', [ProposalPageController::class, 'index'])->name('proposals.index');

    Route::get('/bookkeeping', [BookkeepingPageController::class, 'index'])->name('bookkeeping.index');

    Route::get('/services', [ServicePageController::class, 'index'])->name('services.index');
});
