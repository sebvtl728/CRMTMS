<?php

namespace App\Modules\Sessions\Gdpr;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The fiches of the CRM the module points to (ids only, never their
 * tables), declared to the CRM through Crm\Public\FicheLinks:
 *  - an entreprise: entreprise of a session (intra / individuel),
 *    contractant of a dossier, funder;
 *  - a person: trainee (enrolments), contractant (particulier) or contact of
 *    a dossier, funder.
 * Trashed sessions and dossiers count: they can come back.
 */
final class FicheReferences
{
    public const SAME_SESSION = 'Ces deux personnes sont inscrites à la même session (:session) : annulez l\'une des deux inscriptions avant de les fusionner.';

    /**
     * @var array<'organisation'|'person', array<string, list<string>>> table => columns
     */
    private const COLUMNS = [
        'organisation' => [
            'training_sessions' => ['organisation_id'],
            'dossiers' => ['account_organisation_id'],
            'fundings' => ['funder_organisation_id'],
        ],
        'person' => [
            'enrolments' => ['person_id'],
            'dossiers' => ['account_person_id', 'contact_person_id'],
            'fundings' => ['funder_person_id'],
        ],
    ];

    /**
     * Whether a session, a dossier, an enrolment or a funding names the
     * fiche (an import undo then keeps it).
     */
    public static function linked(string $type, string $id): bool
    {
        foreach (self::COLUMNS[$type] ?? [] as $table => $columns) {
            $query = DB::table($table)->where(function ($q) use ($columns, $id): void {
                foreach ($columns as $column) {
                    $q->orWhere($column, $id);
                }
            });
            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge of two fiches (in the merge transaction): every reference to the
     * absorbed fiche goes to the kept one. Two people enrolled (not
     * cancelled) on the same session would make one trainee enrolled twice:
     * the merge is refused, nothing is written.
     *
     * @throws ValidationException
     */
    public static function merge(string $type, string $keepId, string $dropId): void
    {
        if ($type === 'person') {
            $twice = DB::table('enrolments as dropped')
                ->join('enrolments as kept', 'kept.training_session_id', '=', 'dropped.training_session_id')
                ->join('training_sessions as s', 's.id', '=', 'dropped.training_session_id')
                ->where('dropped.person_id', $dropId)->where('kept.person_id', $keepId)
                ->where('dropped.status', '<>', 'cancelled')->where('kept.status', '<>', 'cancelled')
                ->value('s.number');
            if (is_string($twice)) {
                throw ValidationException::withMessages(['drop' => str_replace(':session', $twice, self::SAME_SESSION)]);
            }
        }

        $now = now();
        foreach (self::COLUMNS[$type] ?? [] as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->where($column, $dropId)->update([$column => $keepId, 'updated_at' => $now]);
            }
        }
    }
}
