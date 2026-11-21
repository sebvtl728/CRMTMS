<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The online signing part of the anonymisation of a person (called by
 * EnrolmentAnonymisation, in the Anonymiser's transaction): her drawings,
 * address and browser erased (the hour of signing stays: proof of
 * schedule); the signed sheets of an individual session (her alone) deleted;
 * a collective session's sheets kept (collective proof, as the scans).
 * Files go after the commit.
 */
final class SigningAnonymisation
{
    /**
     * @param  list<string>  $enrolmentIds  her enrolments
     * @param  list<string>  $individualSessionIds  her individual sessions
     */
    public static function scrub(array $enrolmentIds, array $individualSessionIds): void
    {
        $paths = [];
        if ($enrolmentIds !== []) {
            $signatures = DB::table('attendance_signatures')->whereIn('enrolment_id', $enrolmentIds);
            $paths = $signatures->clone()->whereNotNull('drawing_path')->pluck('drawing_path')->all();
            $signatures->clone()->where(fn ($q) => $q->whereNotNull('drawing_path')->orWhereNotNull('ip')->orWhereNotNull('user_agent'))
                ->update(['drawing_path' => null, 'ip' => null, 'user_agent' => null, 'updated_at' => now()]);
        }
        if ($individualSessionIds !== []) {
            $sheets = DB::table('attendance_signed_sheets')->whereIn('training_session_id', $individualSessionIds);
            $paths = [...$paths, ...$sheets->clone()->pluck('path')->all()];
            $sheets->clone()->delete();
        }
        if ($paths !== []) {
            DB::afterCommit(fn () => Storage::disk(TenantStorage::DISK)->delete($paths));
        }
    }
}
