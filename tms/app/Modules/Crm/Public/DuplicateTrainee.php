<?php

namespace App\Modules\Crm\Public;

use RuntimeException;

/**
 * Trainees::create() found people resembling the new trainee: the caller
 * offers them (identity only, as Trainees::search) or creates anyway with
 * confirm_duplicate.
 */
final class DuplicateTrainee extends RuntimeException
{
    public const MESSAGE = 'Des personnes similaires existent déjà.';

    /**
     * @param  list<array{id: string, last_name: string, first_name: ?string, birth_date: ?string, organisation_label: ?string}>  $candidates
     */
    public function __construct(public readonly array $candidates)
    {
        parent::__construct(self::MESSAGE);
    }
}
