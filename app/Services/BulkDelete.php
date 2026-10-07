<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Mass delete that applies the same per-record rules as single delete.
 * Each record is deleted in its own transaction through Eloquent (so every
 * removal is audited); records that may not be deleted are skipped and
 * reported instead of failing the whole batch.
 */
class BulkDelete
{
    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  list<int>  $ids
     * @param  callable(T): ?string  $blocker  reason the record may not be deleted, or null
     * @param  callable(T): void  $delete  performs the deletion
     * @param  callable(T): string  $label  human name for the skipped list
     */
    public function run(string $model, array $ids, callable $blocker, callable $delete, callable $label): JsonResponse
    {
        $records = $model::query()->whereKey($ids)->get()->keyBy(fn (Model $m) => $m->getKey());
        $deleted = 0;
        $skipped = [];

        foreach ($ids as $id) {
            $record = $records->get($id);

            if (! $record) {
                $skipped[] = ['id' => $id, 'name' => "#{$id}", 'reason' => 'Not found (it may already have been deleted).'];

                continue;
            }

            if ($reason = $blocker($record)) {
                $skipped[] = ['id' => $id, 'name' => $label($record), 'reason' => $reason];

                continue;
            }

            DB::transaction(fn () => $delete($record));
            $deleted++;
        }

        return response()->json(['deleted' => $deleted, 'skipped' => $skipped]);
    }
}
