<?php

namespace App\Modules\Sessions\Attendance;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The proof of an absence (table attendance_proofs), written by
 * AttendanceProofs only: a medical certificate or a summons may reveal
 * health data, so it is not journaled and only managers read it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $seance_id
 * @property string $enrolment_id
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AttendanceProof extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'attendance_proofs';

    /** @var list<string> */
    protected $fillable = ['seance_id', 'enrolment_id', 'path', 'original_name', 'mime', 'size_bytes', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
