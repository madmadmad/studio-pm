<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountingPeriodController;
use App\Http\Controllers\AccountMappingController;
use App\Http\Controllers\AppearanceController;
use App\Http\Controllers\BrandColorController;
use App\Http\Controllers\Chat\ChatMessageController;
use App\Http\Controllers\Chat\ConversationController;
use App\Http\Controllers\Chat\GifController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InvoiceCategoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\MessageAttachmentController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlaidController;
use App\Http\Controllers\Portal\MessageAttachmentController as PortalMessageAttachmentController;
use App\Http\Controllers\Portal\MessageController as PortalMessageController;
use App\Http\Controllers\Portal\ProfileController as PortalProfileController;
use App\Http\Controllers\Portal\TaskController as PortalTaskController;
use App\Http\Controllers\PortalInviteController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectAssignmentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectFavoriteController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\ScheduleItemController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\StudioLogoController;
use App\Http\Controllers\StudioProfileController;
use App\Http\Controllers\SubtaskController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskFileController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->name('api.')->group(function () {
    // Projects/tasks/time entries are open to both roles -- each controller
    // scopes what a Team Member actually sees/touches via the Policies.
    Route::apiResource('companies.projects', ProjectController::class)->shallow();
    Route::apiResource('projects.tasks', TaskController::class)->shallow();
    Route::apiResource('tasks.subtasks', SubtaskController::class)->shallow()->only(['store', 'update', 'destroy']);
    Route::apiResource('tasks.files', TaskFileController::class)->shallow()->only(['store', 'destroy']);
    Route::apiResource('projects.notes', NoteController::class)->shallow()->only(['index', 'store', 'update', 'destroy']);
    Route::put('projects/{project}/schedule-items/order', [ScheduleItemController::class, 'reorder']);
    Route::apiResource('projects.schedule-items', ScheduleItemController::class)->shallow()->only(['index', 'store', 'update', 'destroy']);
    Route::get('projects/{project}/messages/version', [MessageController::class, 'version']);
    Route::apiResource('projects.messages', MessageController::class)->shallow()->only(['index', 'store']);
    Route::patch('messages/{message}', [MessageController::class, 'update']);
    Route::delete('messages/{message}', [MessageController::class, 'destroy']);
    Route::post('messages/{message}/replies', [MessageController::class, 'reply']);
    Route::post('messages/{message}/join', [MessageController::class, 'join']);
    Route::post('messages/{message}/read', [MessageController::class, 'read']);
    Route::post('messages/{message}/reactions', [MessageController::class, 'react']);
    Route::get('attachments/{attachment}', [MessageAttachmentController::class, 'show']);
    Route::get('attachments/{attachment}/thumbnail', [MessageAttachmentController::class, 'thumbnail']);
    Route::get('attachments/{attachment}/display', [MessageAttachmentController::class, 'display']);

    // Chat (staff only -- the `staff` middleware, then the policies).
    Route::middleware('staff')->prefix('chat')->group(function () {
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::get('unread', [ConversationController::class, 'unread']);
        Route::get('channels', [ConversationController::class, 'channels']);
        Route::get('gifs', [GifController::class, 'index']);
        Route::post('channels', [ConversationController::class, 'storeChannel']);
        Route::patch('channels/{conversation}', [ConversationController::class, 'updateChannel']);
        Route::post('channels/{conversation}/join', [ConversationController::class, 'join']);
        Route::post('channels/{conversation}/leave', [ConversationController::class, 'leave']);
        Route::post('direct', [ConversationController::class, 'direct']);
        Route::post('direct/{conversation}/close', [ConversationController::class, 'close']);
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'read']);
        Route::get('conversations/{conversation}/messages', [ChatMessageController::class, 'index']);
        Route::post('conversations/{conversation}/messages', [ChatMessageController::class, 'store']);
        Route::patch('messages/{message}', [ChatMessageController::class, 'update']);
        Route::delete('messages/{message}', [ChatMessageController::class, 'destroy']);
        Route::post('messages/{message}/reactions', [ChatMessageController::class, 'react']);
    });

    Route::post('profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::patch('profile/appearance', [AppearanceController::class, 'update']);
    Route::patch('profile/brand-color', [BrandColorController::class, 'update']);
    Route::delete('profile/avatar', [ProfileController::class, 'destroyAvatar']);
    Route::patch('profile/bio', [ProfileController::class, 'updateBio']);
    Route::post('profile/bio-photo', [ProfileController::class, 'updateBioPhoto']);
    Route::delete('profile/bio-photo', [ProfileController::class, 'destroyBioPhoto']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);

    Route::get('time-entries', [TimeEntryController::class, 'index']);
    Route::post('time-entries', [TimeEntryController::class, 'store']);
    Route::patch('time-entries/{timeEntry}', [TimeEntryController::class, 'update']);
    Route::delete('time-entries/{timeEntry}', [TimeEntryController::class, 'destroy']);
    Route::get('timesheets/weekly', [TimeEntryController::class, 'weekly']);

    Route::post('projects/{project}/favorite', [ProjectFavoriteController::class, 'store']);
    Route::delete('projects/{project}/favorite', [ProjectFavoriteController::class, 'destroy']);

    Route::post('projects/{project}/assignments', [ProjectAssignmentController::class, 'store']);
    Route::delete('projects/{project}/assignments/{user}', [ProjectAssignmentController::class, 'destroy']);

    // Each part of the studio behind its permission (config/permissions.php);
    // a super admin has them all, and settings and team are theirs alone.
    Route::middleware('permission:clients')->group(function () {
        Route::apiResource('companies', CompanyController::class);
        Route::apiResource('companies.contacts', ContactController::class)->shallow();
        Route::post('contacts/{contact}/portal-invite', [PortalInviteController::class, 'store']);
        Route::post('contacts/{contact}/portal-invite/resend', [PortalInviteController::class, 'resend']);
        Route::delete('contacts/{contact}/portal-invite', [PortalInviteController::class, 'destroy']);
    });

    Route::middleware('permission:services')->apiResource('services', ServiceController::class);

    Route::middleware('permission:invoices')->group(function () {
        Route::apiResource('companies.invoices', InvoiceController::class)->shallow()->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->middleware('throttle:invoice-send');
        Route::post('invoices/{invoice}/email-preview', [InvoiceController::class, 'emailPreview']);
        Route::post('invoices/{invoice}/regenerate-token', [InvoiceController::class, 'regenerateToken']);
        Route::post('invoices/{invoice}/reminders/skip', [InvoiceController::class, 'skipReminder']);
        Route::post('invoices/{invoice}/sends/{invoiceSend}/cancel', [InvoiceController::class, 'cancelSend']);
        Route::post('invoices/{invoice}/sends/{invoiceSend}/reschedule', [InvoiceController::class, 'rescheduleSend']);
        Route::post('invoices/{invoice}/sends/{invoiceSend}/send-now', [InvoiceController::class, 'sendNow']);
        Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid']);
        Route::post('invoices/{invoice}/repeat/stop', [InvoiceController::class, 'stopRepeat']);
    });
    // Invoices pick a category; Settings manages the list.
    Route::middleware('permission:invoices,settings')->get('invoice-categories', [InvoiceCategoryController::class, 'index']);

    Route::middleware('permission:proposals')->group(function () {
        Route::apiResource('companies.proposals', ProposalController::class)->shallow()->only(['index', 'store', 'update', 'destroy']);
        Route::get('proposals/{proposal}/send-context', [ProposalController::class, 'sendContext']);
        Route::post('proposals/{proposal}/email-preview', [ProposalController::class, 'emailPreview']);
        Route::post('proposals/{proposal}/send', [ProposalController::class, 'send']);
        Route::post('proposals/{proposal}/unaccept', [ProposalController::class, 'unaccept']);
        // Who can be put in a proposal's team section.
        Route::get('proposal-team', [ProposalController::class, 'teamOptions']);
    });

    Route::middleware('permission:bookkeeping')->group(function () {
        Route::apiResource('transactions', TransactionController::class)->only(['index', 'store', 'destroy']);
        Route::get('bookkeeping/summary', [TransactionController::class, 'summary']);
        // What each category and service posts to in the ledger.
        Route::patch('account-mappings/expense-categories/{expenseCategory}', [AccountMappingController::class, 'expenseCategory']);
        Route::patch('account-mappings/services/{service}', [AccountMappingController::class, 'service']);
        Route::patch('account-mappings/invoice-categories/{invoiceCategory}', [AccountMappingController::class, 'invoiceCategory']);
        // Entries made by hand, and the chart's accounts.
        Route::post('journal-entries', [JournalEntryController::class, 'store']);
        Route::post('journal-entries/transfer', [JournalEntryController::class, 'transfer']);
        Route::post('journal-entries/payout', [JournalEntryController::class, 'payout']);
        Route::post('journal-entries/payroll', [JournalEntryController::class, 'payroll']);
        Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse']);
        Route::post('accounts', [AccountController::class, 'store']);
        Route::patch('accounts/{account}', [AccountController::class, 'update']);
    });

    Route::middleware('permission:expenses')->group(function () {
        Route::apiResource('expense-categories', ExpenseCategoryController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('taxes', TaxController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('expenses', ExpenseController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->name('expenses.receipt');
        Route::post('expenses/{expense}/attach-to-invoice', [ExpenseController::class, 'attachToInvoice']);
        Route::post('expenses/{expense}/detach-from-invoice', [ExpenseController::class, 'detachFromInvoice']);
    });

    Route::middleware('permission:settings')->group(function () {
        // Closing the books for a stretch of dates.
        Route::post('accounting-periods', [AccountingPeriodController::class, 'store']);
        Route::post('accounting-periods/{accountingPeriod}/unlock', [AccountingPeriodController::class, 'unlock']);
        Route::patch('studio-profile', [StudioProfileController::class, 'update']);
        Route::post('studio-profile/logo/{variant}', [StudioLogoController::class, 'store']);
        Route::delete('studio-profile/logo/{variant}', [StudioLogoController::class, 'destroy']);
        Route::apiResource('invoice-categories', InvoiceCategoryController::class)->only(['store', 'update', 'destroy']);
        // Bank feeds (Plaid), in Settings: connect, sync, disconnect.
        Route::post('plaid/link-token', [PlaidController::class, 'linkToken']);
        Route::post('plaid/items', [PlaidController::class, 'store']);
        Route::post('plaid/sync', [PlaidController::class, 'sync']);
        Route::delete('plaid/items/{plaidItem}', [PlaidController::class, 'destroy']);
    });

    Route::middleware('permission:team')->group(function () {
        Route::apiResource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('users/{user}/resend-invite', [UserController::class, 'resendInvite']);
        Route::post('users/{user}/reactivate', [UserController::class, 'reactivate']);
        Route::delete('users/{user}/permanent', [UserController::class, 'forceDestroy']);
        Route::post('users/{user}/avatar', [UserController::class, 'updateAvatar']);
        Route::delete('users/{user}/avatar', [UserController::class, 'destroyAvatar']);
        Route::post('users/{user}/bio-photo', [UserController::class, 'updateBioPhoto']);
        Route::delete('users/{user}/bio-photo', [UserController::class, 'destroyBioPhoto']);
    });
});

// Client Hub mutations -- separate guard, separate controllers, since Gate
// policies are keyed to the staff User model (see Policies\Portal\*).
Route::middleware(['auth:client', 'portal.access', 'portal.preview'])->prefix('portal')->name('api.portal.')->group(function () {
    Route::post('projects/{project}/tasks', [PortalTaskController::class, 'store']);
    Route::patch('tasks/{task}', [PortalTaskController::class, 'update']);

    Route::get('projects/{project}/messages/version', [PortalMessageController::class, 'version']);
    Route::get('projects/{project}/messages', [PortalMessageController::class, 'index']);
    Route::post('projects/{project}/messages', [PortalMessageController::class, 'store']);
    Route::patch('messages/{message}', [PortalMessageController::class, 'update']);
    Route::delete('messages/{message}', [PortalMessageController::class, 'destroy']);
    Route::post('messages/{message}/replies', [PortalMessageController::class, 'reply']);
    Route::post('messages/{message}/reactions', [PortalMessageController::class, 'react']);
    Route::post('messages/{message}/read', [PortalMessageController::class, 'read']);
    Route::get('attachments/{attachment}', [PortalMessageAttachmentController::class, 'show']);
    Route::get('attachments/{attachment}/thumbnail', [PortalMessageAttachmentController::class, 'thumbnail']);
    Route::get('attachments/{attachment}/display', [PortalMessageAttachmentController::class, 'display']);

    Route::post('profile/avatar', [PortalProfileController::class, 'updateAvatar']);
    Route::patch('profile/appearance', [AppearanceController::class, 'update']);
    Route::delete('profile/avatar', [PortalProfileController::class, 'destroyAvatar']);
});

// Public, no auth -- the client-facing surface for proposals. Much smaller
// than a full client portal: one document, one Accept button, no login.
Route::get('proposals/{token}', [ProposalController::class, 'showPublic']);
Route::post('proposals/{token}/accept', [ProposalController::class, 'accept']);

// Public, no auth -- the "Pay Now" button on the client-facing invoice page.
Route::post('invoices/{token}/checkout', [InvoiceController::class, 'checkout']);
