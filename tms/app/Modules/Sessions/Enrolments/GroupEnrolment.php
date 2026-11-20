<?php

namespace App\Modules\Sessions\Enrolments;

use App\Modules\Catalogue\Trainings;
use App\Modules\Crm\Public\DuplicateTrainee;
use App\Modules\Crm\Public\Trainees;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Sessions\SessionKind;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Enrolment of a group of trainees on a session for one line of a dossier
 * (a group journey, and the later waves of it): one transaction for the
 * batch, each trainee through Enrolments::enrolInBatch() (places of an inter
 * session - the extras on the waiting list, in order -, intra entreprise,
 * duplicates, hours). A trainee is a person of the CRM ({person_id}) or one
 * created on the spot ({new_person: {…, employee, confirm_duplicate, ref}},
 * the same ref being one person, as in the dossier assistant); the shares
 * of the line are computed once, after the batch. The entry
 * day is common (default the session start) and may be given per trainee.
 * Every trainee is checked; one refused, nothing is written and the errors
 * come back per trainee: trainees.<j> (the person) or trainees.<j>.<field>;
 * a cancelled dossier or session stops the batch (409).
 */
final class GroupEnrolment
{
    public const MAX = 50;

    public const EMPTY = 'Choisissez au moins un stagiaire.';

    /** Fields of a person created on the spot. */
    private const NEW_PERSON_FIELDS = ['civility', 'first_name', 'last_name', 'email', 'phone', 'mobile', 'birth_date'];

    /** Fields of a trainee given to Enrolments::enrol(). */
    private const EXTRA_FIELDS = ['entered_on', 'planned_hours'];

    public function __construct(
        private readonly Enrolments $enrolments,
        private readonly Trainees $trainees,
        private readonly Trainings $trainings,
    ) {}

    /**
     * @param  list<mixed>|mixed  $trainees
     * @return array{enrolled: int, waitlisted: int}
     *
     * @throws ValidationException
     */
    public function enrol(Dossier $dossier, DossierLine $line, TrainingSession $session, mixed $trainees, ?string $enteredOn = null): array
    {
        $enteredOn = is_string($enteredOn) && trim($enteredOn) !== '' ? trim($enteredOn) : null;
        Validator::make(['trainees' => $trainees, 'entered_on' => $enteredOn], [
            'trainees' => ['required', 'array', 'list', 'max:'.self::MAX],
            'entered_on' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'trainees.required' => self::EMPTY,
            'trainees.max' => 'Au plus '.self::MAX.' stagiaires à la fois.',
            'trainees.*' => self::EMPTY,
            'entered_on.*' => 'Date d\'entrée invalide.',
        ])->validate();
        assert(is_array($trainees));
        if ($session->kind === SessionKind::Individual->value && count($trainees) > 1) {
            throw ValidationException::withMessages(['trainees' => Enrolments::INDIVIDUAL_TAKEN]);
        }
        $das = $this->trainings->find($session->training_id)?->das_id;

        return DB::transaction(function () use ($dossier, $line, $session, $trainees, $enteredOn, $das): array {
            // The dossier, then the session (the order of Enrolments), for the whole batch.
            $dossier = Dossier::withTrashed()->lockForUpdate()->findOrFail($dossier->id);
            TrainingSession::withTrashed()->lockForUpdate()->findOrFail($session->id);

            $errors = [];
            $created = [];
            $counts = ['enrolled' => 0, 'waitlisted' => 0];
            foreach (array_values($trainees) as $j => $trainee) {
                $trainee = is_array($trainee) ? $trainee : [];
                try {
                    $personId = $this->person($j, $trainee, $dossier, $das, $created);
                    $extra = array_intersect_key($trainee, array_flip(self::EXTRA_FIELDS));
                    // No day of his own (absent, null or blank): the common one.
                    if (! is_string($extra['entered_on'] ?? null) || trim($extra['entered_on']) === '') {
                        $extra['entered_on'] = $enteredOn;
                    }
                    $enrolment = $this->enrolments->enrolInBatch($dossier, $line, $session, $personId, $extra);
                    $counts[$enrolment->status === EnrolmentStatus::Waitlisted ? 'waitlisted' : 'enrolled']++;
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $key => $messages) {
                        $errors[self::key($j, $key)] = $messages;
                    }
                }
            }
            if ($errors !== []) {
                // Nothing of the batch is kept.
                throw ValidationException::withMessages($errors);
            }
            // The shares of the line, once for the whole batch.
            $fresh = DossierLine::query()->where('dossier_id', $dossier->id)->find($line->id);
            if ($fresh instanceof DossierLine) {
                PriceShares::recompute($fresh);
            }

            return $counts;
        });
    }

    /**
     * « 4 stagiaires inscrits, 2 en liste d'attente. »
     *
     * @param  array{enrolled: int, waitlisted: int}  $counts
     */
    public static function summary(array $counts): string
    {
        $parts = [];
        if ($counts['enrolled'] > 0 || $counts['waitlisted'] === 0) {
            $parts[] = $counts['enrolled'] > 1 ? "{$counts['enrolled']} stagiaires inscrits" : "{$counts['enrolled']} stagiaire inscrit";
        }
        if ($counts['waitlisted'] > 0) {
            $parts[] = $counts['enrolled'] > 0 ? "{$counts['waitlisted']} en liste d'attente"
                : ($counts['waitlisted'] > 1 ? "{$counts['waitlisted']} stagiaires en liste d'attente" : "1 stagiaire en liste d'attente");
        }

        return ucfirst(implode(', ', $parts)).'.';
    }

    /**
     * « Des personnes similaires existent déjà : … » (the dossier assistant too).
     */
    public static function duplicateMessage(DuplicateTrainee $e): string
    {
        $names = implode(', ', array_map(fn (array $c) => trim(($c['first_name'] ?? '').' '.$c['last_name']).($c['organisation_label'] ? " ({$c['organisation_label']})" : ''), array_slice($e->candidates, 0, 5)));

        return "Des personnes similaires existent déjà : {$names}. Choisissez-la dans la recherche, ou cochez « Créer quand même ».";
    }

    /**
     * The person of a trainee: an existing one, or one created (once per ref)
     * with the DAS of the training, employed by the contractant with « employee ».
     *
     * @param  array<array-key, mixed>  $trainee
     * @param  array<string, string>  $created  ref => person id
     *
     * @throws ValidationException
     */
    private function person(int $j, array $trainee, Dossier $dossier, ?string $das, array &$created): string
    {
        if (is_string($trainee['person_id'] ?? null) && $trainee['person_id'] !== '') {
            return $trainee['person_id'];
        }
        $input = $trainee['new_person'] ?? null;
        if (! is_array($input)) {
            throw ValidationException::withMessages(['person_id' => 'Choisissez le stagiaire.']);
        }
        $ref = is_string($input['ref'] ?? null) && trim($input['ref']) !== '' ? mb_substr(trim($input['ref']), 0, 100) : null;
        if ($ref !== null && isset($created[$ref])) {
            return $created[$ref];
        }
        $employer = $dossier->account_organisation_id !== null && filter_var($input['employee'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ? $dossier->account_organisation_id : null;
        try {
            $id = $this->trainees->create([
                ...array_intersect_key($input, array_flip(self::NEW_PERSON_FIELDS)),
                'organisation_id' => $employer,
                'das_ids' => $das === null ? [] : [$das],
                'confirm_duplicate' => filter_var($input['confirm_duplicate'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);
        } catch (DuplicateTrainee $e) {
            throw ValidationException::withMessages(['person_id' => self::duplicateMessage($e)]);
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $key => $errors) {
                $messages["new_person.{$key}"] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }
        if ($ref !== null) {
            $created[$ref] = $id;
        }

        return $id;
    }

    /**
     * Where an error of trainee $j goes: the session and the line are the
     * batch's (training, intra entreprise, individual session taken).
     */
    private static function key(int $j, string $key): string
    {
        return match ($key) {
            'person_id' => "trainees.{$j}",
            'training_session_id', 'dossier_line_id' => $key,
            default => "trainees.{$j}.{$key}",
        };
    }
}
