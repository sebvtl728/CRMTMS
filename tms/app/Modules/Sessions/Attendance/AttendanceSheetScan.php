<?php

namespace App\Modules\Sessions\Attendance;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A scanned, signed attendance sheet (table attendance_sheet_scans), written
 * by AttendanceScans only: attached to a séance, or (seance_id null) to a
 * day of a session. The file name typed by the sender may name a trainee: it
 * is never journaled.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $training_session_id
 * @property string|null $seance_id
 * @property CarbonImmutable $date
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 */
class AttendanceSheetScan extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'attendance_sheet_scan';

    protected $table = 'attendance_sheet_scans';

    /** @var list<string> */
    protected array $auditIgnore = ['path', 'uploaded_by'];

    /** @var list<string> */
    protected array $auditPersonal = ['original_name'];

    /** @var list<string> */
    protected $fillable = ['training_session_id', 'seance_id', 'date', 'path', 'original_name', 'mime', 'size_bytes', 'uploaded_by'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'size_bytes' => 'integer',
        ];
    }
}
