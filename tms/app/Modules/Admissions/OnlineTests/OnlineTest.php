<?php

namespace App\Modules\Admissions\OnlineTests;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * An online positioning test: its draft (OnlineTestContent), the DAS and
 * trainings it serves (online_test_das, online_test_trainings), an optional
 * time limit; current_version_id is the version offered to candidates.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $title
 * @property array<string, mixed> $draft_content
 * @property int|null $time_limit_minutes
 * @property string|null $current_version_id
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class OnlineTest extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MORPH_ALIAS = 'online_test';

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by', 'draft_content'];

    /** @var list<string> */
    protected $fillable = ['title', 'draft_content', 'time_limit_minutes'];

    protected function casts(): array
    {
        return ['draft_content' => 'array', 'time_limit_minutes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (OnlineTest $test): void {
            $test->created_by ??= Auth::id();
            $test->updated_by ??= Auth::id();
        });
        static::updating(function (OnlineTest $test): void {
            if (Auth::id() !== null) {
                $test->updated_by = Auth::id();
            }
        });
    }
}
