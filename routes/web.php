<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\Dev\NotificationPreviewController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\Web\AcceptInvitationController;
use App\Http\Controllers\Web\BookkeepingPageController;
use App\Http\Controllers\Web\ClientPageController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ExpensePageController;
use App\Http\Controllers\Web\InvoicePageController;
use App\Http\Controllers\Web\PortalAuthController;
use App\Http\Controllers\Web\PortalPageController;
use App\Http\Controllers\Web\PortalPreviewController;
use App\Http\Controllers\Web\PortalProfilePageController;
use App\Http\Controllers\Web\ProfilePageController;
use App\Http\Controllers\Web\ProjectPageController;
use App\Http\Controllers\Web\ProposalPageController;
use App\Http\Controllers\Web\PublicInvoiceController;
use App\Http\Controllers\Web\PublicProposalController;
use App\Http\Controllers\Web\ServicePageController;
use App\Http\Controllers\Web\SettingsPageController;
use App\Http\Controllers\Web\TimePageController;
use App\Http\Controllers\Web\UserPageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Avatars carry no sensitive business data, so unlike every other private
// upload in this app they're reachable by any authenticated actor of
// either guard rather than being scoped to a specific project -- see
// AvatarController's own docblock. Outside both the "auth"/"auth:client"
// groups below since either guard should work here.
Route::get('/avatars/users/{user}', [AvatarController::class, 'user'])->name('avatars.user');
Route::get('/avatars/contacts/{contact}', [AvatarController::class, 'contact'])->name('avatars.contact');
Route::get('/bio-photos/users/{user}', [AvatarController::class, 'userBioPhoto'])->name('bio-photos.user');

// Login, logout, and password reset are all registered by Fortify (see
// FortifyServiceProvider) -- it owns /login, /logout, /forgot-password, and
// /reset-password/{token}, pointed at our own Inertia pages via loginView()
// etc. rather than its default Blade views.

Route::middleware('guest')->group(function () {
    Route::get('/invite/{token}', [AcceptInvitationController::class, 'show'])->name('invite.show');
    Route::post('/invite/{token}', [AcceptInvitationController::class, 'store'])->name('invite.store');
});

Route::get('/p/{token}', [PublicProposalController::class, 'show'])->name('proposals.public');
Route::get('/p/{token}/pdf', [PublicProposalController::class, 'pdf'])->name('proposals.public.pdf');
// A team section photo, only for someone in that proposal's team.
Route::get('/p/{token}/team/{user}/photo', [PublicProposalController::class, 'teamPhoto'])->name('proposals.public.team-photo');
Route::get('/i/{token}', [PublicInvoiceController::class, 'show'])->name('invoices.public');
Route::get('/i/{token}/pdf', [PublicInvoiceController::class, 'pdf'])->name('invoices.public.pdf');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Open to all staff -- each page controller scopes its own data by
    // permission (assigned projects only, without all_projects).
    Route::get('/projects', [ProjectPageController::class, 'index'])->name('projects.index');
    Route::get('/projects/archived', [ProjectPageController::class, 'archived'])->middleware('permission:all_projects')->name('projects.archived');
    Route::get('/projects/{project}', [ProjectPageController::class, 'show'])->name('projects.show');

    Route::get('/time-entries', [TimePageController::class, 'index'])->name('time.index');
    Route::get('/timesheets', [TimePageController::class, 'weekly'])->name('timesheets.index');

    Route::get('/profile', [ProfilePageController::class, 'index'])->name('profile.index');

    // Each part of the studio behind its permission (config/permissions.php).
    // A super admin has them all; settings and team are theirs alone.
    Route::middleware('permission:clients')->group(function () {
        Route::get('/clients', [ClientPageController::class, 'index'])->name('clients.index');
        Route::get('/clients/{company}', [ClientPageController::class, 'show'])->name('clients.show');
        Route::get('/clients/{company}/portal-preview', [PortalPreviewController::class, 'start'])->name('clients.portal-preview');
    });

    Route::middleware('permission:invoices')->group(function () {
        Route::get('/invoices', [InvoicePageController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{invoice}/pdf', [InvoicePageController::class, 'pdf'])->name('invoices.pdf');
        Route::get('/invoices/{invoice}', [InvoicePageController::class, 'show'])->name('invoices.show');
    });

    Route::middleware('permission:proposals')->group(function () {
        Route::get('/proposals', [ProposalPageController::class, 'index'])->name('proposals.index');
        Route::get('/proposals/create', [ProposalPageController::class, 'create'])->name('proposals.create');
        Route::get('/proposals/{proposal}/edit', [ProposalPageController::class, 'edit'])->name('proposals.edit');
    });

    Route::middleware('permission:bookkeeping')->group(function () {
        Route::get('/bookkeeping', [BookkeepingPageController::class, 'index'])->name('bookkeeping.index');
        Route::get('/bookkeeping/sales-tax', [BookkeepingPageController::class, 'salesTax'])->name('bookkeeping.sales-tax');
        Route::get('/bookkeeping/sales-tax.csv', [BookkeepingPageController::class, 'salesTaxCsv'])->name('bookkeeping.sales-tax.csv');
        Route::get('/bookkeeping/invoice-categories', [BookkeepingPageController::class, 'invoiceCategories'])->name('bookkeeping.invoice-categories');
        Route::get('/bookkeeping/invoice-categories.csv', [BookkeepingPageController::class, 'invoiceCategoriesCsv'])->name('bookkeeping.invoice-categories.csv');
        Route::get('/bookkeeping/hosting', [BookkeepingPageController::class, 'hosting'])->name('bookkeeping.hosting');
        Route::get('/bookkeeping/hosting.csv', [BookkeepingPageController::class, 'hostingCsv'])->name('bookkeeping.hosting.csv');
        Route::get('/bookkeeping/profit-loss', [BookkeepingPageController::class, 'profitLoss'])->name('bookkeeping.profit-loss');
        Route::get('/bookkeeping/profit-loss.csv', [BookkeepingPageController::class, 'profitLossCsv'])->name('bookkeeping.profit-loss.csv');
        Route::get('/bookkeeping/invoices.csv', [BookkeepingPageController::class, 'invoicesCsv'])->name('bookkeeping.invoices.csv');
        Route::get('/bookkeeping/expenses.csv', [BookkeepingPageController::class, 'expensesCsv'])->name('bookkeeping.expenses.csv');
    });

    Route::middleware('permission:expenses')->get('/expenses', [ExpensePageController::class, 'index'])->name('expenses.index');

    Route::middleware('permission:services')->get('/services', [ServicePageController::class, 'index'])->name('services.index');

    Route::middleware('permission:team')->get('/users', [UserPageController::class, 'index'])->name('users.index');

    Route::middleware('permission:settings')->group(function () {
        Route::get('/settings', [SettingsPageController::class, 'index'])->name('settings.index');

        // The proposal, invoice and notification emails as they're sent,
        // for styling the templates.
        if (app()->isLocal()) {
            Route::get('/dev/mail/proposal/{proposal?}', [ProposalController::class, 'emailBrowserPreview'])->name('dev.mail.proposal');
            Route::get('/dev/mail/invoice/{invoice?}', [InvoiceController::class, 'emailBrowserPreview'])->name('dev.mail.invoice');
            Route::get('/dev/mail/notification/{type?}', [NotificationPreviewController::class, 'show'])->name('dev.mail.notification');
        }

        // Live reference for the shared button/card/field/table classes in
        // resources/css/components.css -- see that file's banner comment.
        Route::get('/style-guide', fn () => Inertia::render('Dev/StyleGuide'))->name('style-guide');
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

    Route::middleware(['auth:client', 'portal.access', 'portal.preview'])->group(function () {
        Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');
        Route::post('/preview/exit', [PortalPreviewController::class, 'exit'])->name('preview.exit');
        Route::get('/', [PortalPageController::class, 'index'])->name('dashboard');
        Route::get('/proposals', [PortalPageController::class, 'proposals'])->name('proposals.index');
        Route::get('/invoices', [PortalPageController::class, 'invoices'])->name('invoices.index');
        Route::get('/contacts', [PortalPageController::class, 'contacts'])->name('contacts.index');
        Route::get('/projects/{project}', [PortalPageController::class, 'show'])->name('projects.show');
        Route::get('/profile', [PortalProfilePageController::class, 'index'])->name('profile.index');
    });
});
