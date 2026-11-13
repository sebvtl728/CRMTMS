<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\LastContact;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A commercial action on an entreprise, a person and/or an opportunity. To
 * do while done_at is null; done, it is a contact: last_contact_at of its
 * fiches (a person's active entreprises too, the fiche of its opportunity)
 * moves forward, and goes back when it is removed or dated earlier.
 *
 * Subject (« Appel – Jean Dupont »), report and place may describe a
 * person: journaled without value, cleared by the anonymisation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $type_id
 * @property string $subject
 * @property string|null $report
 * @property string|null $organisation_id
 * @property string|null $person_id
 * @property string|null $opportunity_id
 * @property Carbon|null $due_at
 * @property Carbon|null $done_at
 * @property Carbon|null $happened_at generated: done_at, else due_at
 * @property int|null $duration_minutes
 * @property string|null $result_id
 * @property string|null $responsible_user_id
 * @property string|null $location
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 */
class Activity extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const STATUS_LABELS = [
        'todo' => 'À faire',
        'overdue' => 'En retard',
        'done' => 'Faite',
    ];

    /** @var list<string> */
    protected array $auditPersonal = ['subject', 'report', 'location'];

    /** @var list<string> */
    protected array $auditIgnore = ['happened_at', 'created_by'];

    /** @var list<string> */
    protected $fillable = [
        'type_id', 'subject', 'report', 'organisation_id', 'person_id', 'opportunity_id',
        'due_at', 'done_at', 'duration_minutes', 'result_id', 'responsible_user_id', 'location',
    ];

    /** Exact instants with their offset (no ambiguity on the autumn DST fold). */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected static function booted(): void
    {
        static::creating(function (Activity $activity): void {
            $activity->created_by ??= Auth::id();
            $activity->responsible_user_id ??= $activity->created_by;
        });
        static::created(fn (Activity $activity) => $activity->done_at !== null ? $activity->recordContact($activity->done_at) : null);
        static::restored(fn (Activity $activity) => $activity->done_at !== null ? $activity->recordContact($activity->done_at) : null);
        static::updated(function (Activity $activity): void {
            if (! $activity->wasChanged('done_at')) {
                return;
            }
            $before = $activity->getRawOriginal('done_at');
            $before = is_string($before) ? CarbonImmutable::parse($before) : null;
            $after = $activity->done_at;

            if ($after !== null && ($before === null || $after->greaterThanOrEqualTo($before))) {
                $activity->recordContact($after);
            } else {
                $activity->recomputeContact();
            }
        });
        static::deleted(fn (Activity $activity) => $activity->done_at !== null ? $activity->recomputeContact() : null);

        // The menu badge of the responsible(s) (new and previous one) is recounted.
        $forgetBadge = function (Activity $activity): void {
            TodoQuery::forget($activity->responsible_user_id);
            $before = $activity->getOriginal('responsible_user_id');
            if (is_string($before) && $before !== $activity->responsible_user_id) {
                TodoQuery::forget($before);
            }
        };
        static::saved($forgetBadge);
        static::deleted($forgetBadge);
        static::restored($forgetBadge);
    }

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'done_at' => 'datetime',
            'happened_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    /**
     * Done, overdue (due before today, Paris calendar: a follow-up due today
     * is not late before midnight) or to do.
     *
     * @return 'done'|'overdue'|'todo'
     */
    public function status(?CarbonInterface $now = null): string
    {
        if ($this->done_at !== null) {
            return 'done';
        }

        $tz = (string) config('app.timezone');
        $today = CarbonImmutable::instance($now ?? now())->setTimezone($tz)->toDateString();
        $due = $this->due_at === null ? null : CarbonImmutable::instance($this->due_at)->setTimezone($tz)->toDateString();

        return $due !== null && $due < $today ? 'overdue' : 'todo';
    }

    /**
     * The fiches this action counts as a contact for: its entreprise, its
     * person and the fiche of its opportunity, as [morph alias, id].
     *
     * @return list<array{0: string, 1: string}>
     */
    public function contactSubjects(): array
    {
        $organisation = (new Organisation)->getMorphClass();
        $person = (new Person)->getMorphClass();
        $subjects = [];

        if ($this->organisation_id !== null) {
            $subjects[] = [$organisation, $this->organisation_id];
        }
        if ($this->person_id !== null) {
            $subjects[] = [$person, $this->person_id];
        }
        if ($this->opportunity_id !== null) {
            $account = DB::table('opportunities')->where('id', $this->opportunity_id)->first(['organisation_id', 'person_id']);
            if ($account !== null && is_string($account->organisation_id)) {
                $subjects[] = [$organisation, $account->organisation_id];
            } elseif ($account !== null && is_string($account->person_id)) {
                $subjects[] = [$person, $account->person_id];
            }
        }

        return array_values(array_unique($subjects, SORT_REGULAR));
    }

    private function recordContact(CarbonInterface $at): void
    {
        foreach ($this->contactSubjects() as [$type, $id]) {
            LastContact::record($type, $id, $at);
        }
    }

    private function recomputeContact(): void
    {
        foreach ($this->contactSubjects() as [$type, $id]) {
            LastContact::recompute($type, $id);
        }
    }

    /**
     * Whether a fiche it hangs on (or its opportunity) is in the trash:
     * readable with crm.delete only, never edited.
     */
    public function ficheTrashed(): bool
    {
        if ($this->organisation_id !== null && ($this->organisation === null || $this->organisation->trashed())) {
            return true;
        }
        if ($this->person_id !== null && ($this->person === null || $this->person->trashed())) {
            return true;
        }
        if ($this->opportunity_id !== null) {
            $opportunity = $this->opportunity;
            $fiche = $opportunity === null ? null : ($opportunity->organisation_id !== null ? $opportunity->organisation : $opportunity->person);

            return $opportunity === null || $opportunity->trashed() || $fiche === null || $fiche->trashed();
        }

        return false;
    }

    /**
     * Name of the fiche for the default subject (« Appel – Jean Dupont »):
     * the person, else the entreprise, else the opportunity's fiche.
     */
    public function ficheName(): string
    {
        if ($this->person !== null) {
            return $this->person->fullName();
        }
        if ($this->organisation !== null) {
            return $this->organisation->name;
        }
        $opportunity = $this->opportunity;

        return $opportunity?->organisation->name ?? $opportunity?->person?->fullName() ?? $opportunity->title ?? '';
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'type_id')->withTrashed();
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'result_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
