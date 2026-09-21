<?php

use App\Http\Controllers\Web\AcceptInvitationController;
use App\Http\Controllers\Web\BookkeepingPageController;
use App\Http\Controllers\Web\ClientPageController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ExpensePageController;
use App\Http\Controllers\Web\InvoicePageController;
use App\Http\Controllers\Web\PortalAuthController;
use App\Http\Controllers\Web\PortalPageController;
use App\Http\Controllers\Web\ProjectPageController;
use App\Http\Controllers\Web\ProposalPageController;
use App\Http\Controllers\Web\PublicInvoiceController;
use App\Http\Controllers\Web\PublicProposalController;
use App\Http\Controllers\Web\ServicePageController;
use App\Http\Controllers\Web\SettingsPageController;
use App\Http\Controllers\Web\TimePageController;
use App\Http\Controllers\Web\UserPageController;
use Illuminate\Support\Facades\Route;

// Login, logout, and password reset are all registered by Fortify (see
// FortifyServiceProvider) -- it owns /login, /logout, /forgot-password, and
// /reset-password/{token}, pointed at our own Inertia pages via loginView()
// etc. rather than its default Blade views.

Route::middleware('guest')->group(function () {
    Route::get('/invite/{token}', [AcceptInvitationController::class, 'show'])->name('invite.show');
    Route::post('/invite/{token}', [AcceptInvitationController::class, 'store'])->name('invite.store');
});

Route::get('/p/{token}', [PublicProposalController::class, 'show'])->name('proposals.public');
Route::get('/i/{token}', [PublicInvoiceController::class, 'show'])->name('invoices.public');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Open to both roles -- each page controller scopes its own data by role.
    Route::get('/projects', [ProjectPageController::class, 'index'])->name('projects.index');
    Route::get('/projects/archived', [ProjectPageController::class, 'archived'])->name('projects.archived');
    Route::get('/projects/{project}', [ProjectPageController::class, 'show'])->name('projects.show');

    Route::get('/time-entries', [TimePageController::class, 'index'])->name('time.index');
    Route::get('/timesheets', [TimePageController::class, 'weekly'])->name('timesheets.index');

    // Firm-wide financials and the client directory -- Managers only.
    Route::middleware('role:manager')->group(function () {
        Route::get('/clients', [ClientPageController::class, 'index'])->name('clients.index');
        Route::get('/clients/{company}', [ClientPageController::class, 'show'])->name('clients.show');

        Route::get('/invoices', [InvoicePageController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{invoice}/pdf', [InvoicePageController::class, 'pdf'])->name('invoices.pdf');
        Route::get('/invoices/{invoice}', [InvoicePageController::class, 'show'])->name('invoices.show');

        Route::get('/proposals', [ProposalPageController::class, 'index'])->name('proposals.index');
        Route::get('/proposals/create', [ProposalPageController::class, 'create'])->name('proposals.create');
        Route::get('/proposals/{proposal}/edit', [ProposalPageController::class, 'edit'])->name('proposals.edit');

        Route::get('/bookkeeping', [BookkeepingPageController::class, 'index'])->name('bookkeeping.index');

        Route::get('/expenses', [ExpensePageController::class, 'index'])->name('expenses.index');

        Route::get('/services', [ServicePageController::class, 'index'])->name('services.index');

        Route::get('/settings', [SettingsPageController::class, 'index'])->name('settings.index');

        Route::get('/users', [UserPageController::class, 'index'])->name('users.index');
    });
});

// Client Hub -- entirely separate guard/session from staff auth above.
Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware('guest:client')->group(function () {
        Route::get('/login', [PortalAuthController::class, 'showRequest'])->name('login');
        Route::post('/login', [PortalAuthController::class, 'sendLink'])->middleware('throttle:magic-link')->name('login.send');
    });

    // Signed, not guest-gated -- a client may click a fresh link while an
    // older session/tab is still open, and the signature is the real gate.
    Route::get('/login/verify/{contactId}/{token}', [PortalAuthController::class, 'verify'])->name('verify');

    Route::middleware('auth:client')->group(function () {
        Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');
        Route::get('/', [PortalPageController::class, 'index'])->name('dashboard');
        Route::get('/projects/{project}', [PortalPageController::class, 'show'])->name('projects.show');
    });
});
