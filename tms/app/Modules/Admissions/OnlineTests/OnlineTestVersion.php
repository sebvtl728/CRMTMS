<?php

namespace App\Modules\Admissions\OnlineTests;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A version of an online test frozen at its publication: never changed
 * (a candidate answers and is graded on it).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $online_test_id
 * @property int $number
 * @property array<string, mixed> $content
 * @property int $total_points
 * @property int|null $time_limit_minutes
 * @property string|null $published_by
 * @property Carbon|null $created_at
 */
class OnlineTestVersion extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['online_test_id', 'number', 'content', 'total_points', 'time_limit_minutes', 'published_by'];

    protected function casts(): array
    {
        return ['content' => 'array', 'number' => 'integer', 'total_points' => 'integer', 'time_limit_minutes' => 'integer'];
    }

    /**
     * Every question of the version by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function questions(): array
    {
        $all = [];
        foreach ($this->content['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                $all[(string) $question['id']] = $question;
            }
        }

        return $all;
    }
}
