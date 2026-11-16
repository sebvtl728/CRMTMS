<?php

namespace App\Modules\Core\Exports;

use Illuminate\Database\Eloquent\Model;

/**
 * An export resource that reads what its cells need for a whole chunk of
 * rows at once (labels from other modules, computed values), instead of
 * one query per row: the exporter calls prefetch() before the values of
 * each chunk.
 */
interface PrefetchesRows
{
    /**
     * @param  list<Model>  $models  the rows of the next chunk
     */
    public function prefetch(array $models): void;
}
