<?php

namespace App\Services\Posting;

use App\Exceptions\LedgerException;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// Hooks each kind of record to its Poster, so the ledger follows the
// records without anyone posting by hand. Called from AppServiceProvider.
class PostingHooks
{
    public const POSTERS = [
        Expense::class => ExpensePoster::class,
        Payment::class => PaymentPoster::class,
        Transaction::class => IncomePoster::class,
    ];

    public static function register(): void
    {
        foreach (self::POSTERS as $model => $poster) {
            static::hook($model, $poster);
        }
    }

    /**
     * @param  class-string<Model>  $model
     * @param  class-string<Poster>  $poster
     */
    private static function hook(string $model, string $poster): void
    {
        // As it's written: refuse a change inside a locked period, so the
        // person sees why (a 422) and nothing is half-saved.
        $model::saving(fn (Model $record) => app($poster)->guard($record));
        $model::deleting(fn (Model $record) => app($poster)->guard($record, deleting: true));

        // Once it's committed: bring the ledger in step -- after the
        // commit, so an expense saved with its splits posts once, with the
        // splits. The record is saved by then and a refusal can't undo it,
        // so it's reported (the backfill picks up anything left unposted).
        $sync = fn (Model $record) => DB::afterCommit(function () use ($record, $poster) {
            try {
                app($poster)->sync($record);
            } catch (LedgerException $e) {
                report($e);
            }
        });
        $model::saved($sync);
        $model::deleted($sync);
    }
}
