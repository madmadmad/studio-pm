<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\StudioProfileController;
use App\Http\Controllers\SubtaskController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskFileController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->name('api.')->group(function () {
    Route::apiResource('companies', CompanyController::class);
    Route::apiResource('companies.contacts', ContactController::class)->shallow();
    Route::apiResource('companies.projects', ProjectController::class)->shallow();
    Route::apiResource('projects.tasks', TaskController::class)->shallow();
    Route::apiResource('tasks.subtasks', SubtaskController::class)->shallow()->only(['store', 'update', 'destroy']);
    Route::apiResource('tasks.files', TaskFileController::class)->shallow()->only(['store', 'destroy']);
    Route::apiResource('projects.notes', NoteController::class)->shallow()->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('projects.messages', MessageController::class)->shallow()->only(['index', 'store']);
    Route::apiResource('services', ServiceController::class);

    Route::get('time-entries', [TimeEntryController::class, 'index']);
    Route::post('time-entries', [TimeEntryController::class, 'store']);
    Route::patch('time-entries/{timeEntry}', [TimeEntryController::class, 'update']);
    Route::delete('time-entries/{timeEntry}', [TimeEntryController::class, 'destroy']);
    Route::get('timesheets/weekly', [TimeEntryController::class, 'weekly']);

    Route::apiResource('companies.invoices', InvoiceController::class)->shallow()->only(['index', 'store']);
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send']);
    Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid']);

    Route::apiResource('companies.proposals', ProposalController::class)->shallow()->only(['index', 'store', 'update']);
    Route::post('proposals/{proposal}/send', [ProposalController::class, 'send']);
    Route::post('proposals/{proposal}/unaccept', [ProposalController::class, 'unaccept']);

    Route::apiResource('transactions', TransactionController::class)->only(['index', 'store', 'destroy']);
    Route::get('bookkeeping/summary', [TransactionController::class, 'summary']);

    Route::patch('studio-profile', [StudioProfileController::class, 'update']);
});

// Public, no auth -- the client-facing surface for proposals. Much smaller
// than a full client portal: one document, one Accept button, no login.
Route::get('proposals/{token}', [ProposalController::class, 'showPublic']);
Route::post('proposals/{token}/accept', [ProposalController::class, 'accept']);
