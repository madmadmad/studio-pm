<?php

namespace App\Services\BonsaiImport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// What's already been brought in from Bonsai, by a key stable from one
// export to the next, so running the import again skips it.
class ImportRecords
{
    private const SOURCE = 'bonsai';

    /** @var array<string, array{0: string, 1: int}> */
    private array $known;

    public function __construct()
    {
        $this->known = DB::table('import_records')->where('source', self::SOURCE)->get()
            ->mapWithKeys(fn ($row) => [$row->external_key => [$row->record_type, (int) $row->record_id]])
            ->all();
    }

    public function has(string $key): bool
    {
        return isset($this->known[$key]);
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public function find(string $key, string $class): ?Model
    {
        return isset($this->known[$key]) ? $class::find($this->known[$key][1]) : null;
    }

    public function remember(string $key, Model $record): void
    {
        DB::table('import_records')->insert([
            'source' => self::SOURCE,
            'external_key' => $key,
            'record_type' => $record->getMorphClass(),
            'record_id' => $record->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->known[$key] = [$record->getMorphClass(), (int) $record->getKey()];
    }
}
