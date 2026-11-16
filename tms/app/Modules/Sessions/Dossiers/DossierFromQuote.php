<?php

namespace App\Modules\Sessions\Dossiers;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Catalogue\TrainingData;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\AcceptedQuoteData;
use App\Modules\Crm\Public\AcceptedQuotes;
use App\Modules\Crm\Public\DuplicateTrainee;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Enrolments\EnrolmentOptions;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Fundings\FundingStatus;
use App\Modules\Sessions\Fundings\FundingWriter;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionKind;
use App\Modules\Sessions\Sessions\SessionStatus;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * « Créer le dossier » from an accepted quote (spec lot 2a § 5.5): the
 * summary of the assistant (prepare()), then everything in one transaction
 * (create()):
 *
 *  - the quote (Crm\Public\AcceptedQuotes, seen by the user) is locked and a
 *    dossier per quote only (lock + unique dossiers.quote_id, trashed ones
 *    included): a second attempt is DossierExists (409);
 *  - the dossier keeps the quote, its opportunity, issuer, contractant,
 *    contact (when still live) and the opportunity's DAS; a quote line with a
 *    training becomes a training line (pricing mode chosen, proposed from
 *    the unit), one without a fee line;
 *  - for each training line: an existing session open to the dossier
 *    (EnrolmentOptions::openSessions), a new one (SessionWriter: an intra or
 *    individual one for the contractant entreprise) or none yet; its
 *    trainees found in the CRM or created (Crm\Public\Trainees, with the
 *    training's DAS), enrolled (Enrolments);
 *  - an initial funding « Entreprise » (« Personnel » for a particulier) of
 *    the whole price, « À demander ».
 *
 * Sessions and enrolments need sessions.sessions.update (403 otherwise).
 * Errors are keyed by the plan: lines.<i>.session_id, lines.<i>.new_session.<field>,
 * lines.<i>.trainees.<j>[.<field>].
 *
 * A plan: lines[i] (i = position of the quote line) = {session_id | new_session: {kind,
 * starts_on, ends_on, modality_id, planned_hours, location_kind, location_text, capacity},
 * pricing_mode, trainees: list<person id | {person_id} | {new_person: {first_name,
 * last_name, email, phone, birth_date, employee, confirm_duplicate}}>}.
 */
final class DossierFromQuote
{
    public const NO_SESSIONS_RIGHT = 'Vous n\'avez pas le droit de planifier des sessions ni d\'inscrire des stagiaires.';

    public const OUTSIDE_DAS = 'Le DAS de ce devis n\'est pas dans vos DAS.';

    private const MAX_TRAINEES = 200;

    private const NEW_SESSION_FIELDS = ['kind', 'starts_on', 'ends_on', 'modality_id', 'planned_hours', 'location_kind', 'location_text', 'capacity'];

    private const NEW_PERSON_FIELDS = ['civility', 'first_name', 'last_name', 'email', 'phone', 'mobile', 'birth_date'];

    public function __construct(
        private readonly AcceptedQuotes $quotes,
        private readonly DossierWriter $dossiers,
        private readonly SessionWriter $sessions,
        private readonly Enrolments $enrolments,
        private readonly FundingWriter $fundings,
        private readonly Trainees $trainees,
        private readonly Trainings $trainings,
        private readonly Fiches $fiches,
    ) {}

    /**
     * The dossier already made from the quote $quoteId (trashed included).
     */
    public static function existing(string $quoteId): ?Dossier
    {
        return Str::isUuid($quoteId) ? Dossier::withTrashed()->where('quote_id', $quoteId)->first() : null;
    }

    /**
     * Summary of the quote and, per training line, the sessions open to the
     * dossier (empty without sessions.sessions.update). 404 when the quote is
     * not an accepted one the user sees.
     *
     * @return array{quote: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function prepare(string $quoteId, User $user): array
    {
        $quote = $this->find($quoteId, $user);
        $trainings = $this->trainings->findMany(array_values(array_filter(array_column($quote->lines, 'training_id'))));
        $organisationId = $quote->account['type'] === 'organisation' ? $quote->account['id'] : null;
        $sessions = $user->can(Permissions::SESSIONS_UPDATE) && $trainings !== []
            ? EnrolmentOptions::openSessions(array_keys($trainings), $organisationId, $user)
                ->orderBy('training_sessions.starts_on')->orderBy('training_sessions.number')->limit(EnrolmentOptions::LIMIT)->get()
            : collect();
        $contact = $quote->contact_person_id === null ? null : $this->fiches->person($quote->contact_person_id);

        $lines = [];
        $total = 0;
        foreach ($quote->lines as $i => $line) {
            $training = $line['training_id'] === null ? null : ($trainings[$line['training_id']] ?? null);
            $total += $line['total_ht_cents'];
            $lines[] = [
                'index' => $i,
                'kind' => $training === null ? DossierLine::FEE : DossierLine::TRAINING,
                'label' => $line['label'],
                'training' => $training === null ? null : [
                    'id' => $training->id,
                    'title' => $training->title,
                    'code' => $training->code,
                    'hours' => $training->duration_hours,
                ],
                'quantity' => self::french($line['quantity']),
                'unit' => $line['unit'],
                'unit_price' => Amounts::display($line['unit_price_cents']),
                'discount_percent' => self::french($line['discount_percent']),
                'total_ht' => Amounts::display($line['total_ht_cents']),
                'pricing_mode' => $training === null ? null : DossierLine::proposedPricingMode($line['unit']),
                'sessions' => $training === null ? [] : $sessions->where('training_id', $training->id)
                    ->map(fn (TrainingSession $s) => EnrolmentOptions::sessionOption($s))->values()->all(),
            ];
        }

        return [
            'quote' => [
                'id' => $quote->id,
                'number' => $quote->number,
                'account' => $quote->account,
                'contact' => $contact === null ? null : ['id' => $contact['id'], 'label' => $contact['label']],
                'das' => ReferenceItem::query()->withTrashed()->find($quote->das_id)?->label,
                'total_ht' => Amounts::display($total),
            ],
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     *
     * @throws ValidationException
     */
    public function create(string $quoteId, array $plan, User $user): Dossier
    {
        try {
            return DB::transaction(function () use ($quoteId, $plan, $user): Dossier {
                // The lock serialises two attempts: the second sees the first dossier.
                $quote = $this->find($quoteId, $user, lock: true);
                $existing = self::existing($quote->id);
                if ($existing !== null) {
                    throw new DossierExists($existing);
                }
                if (! SessionsVisibility::allowsDas($user, Permissions::DOSSIERS_UPDATE, $quote->das_id)) {
                    throw new UserFacingHttpException(403, self::OUTSIDE_DAS);
                }
                $trainings = $this->trainings->findMany(array_values(array_filter(array_column($quote->lines, 'training_id'))));
                $lines = $this->validatedPlan($quote, $trainings, $plan, $user);

                $dossier = $this->dossiers->create(self::dossierData($quote, $lines), ['quote_id' => $quote->id, 'opportunity_id' => $quote->opportunity_id]);
                $dossierLines = $dossier->lines()->get()->values();

                foreach ($lines as $i => $line) {
                    if ($line['session'] === null) {
                        continue;
                    }
                    $training = $trainings[(string) $quote->lines[$i]['training_id']];
                    $session = $line['session'] instanceof TrainingSession ? $line['session']
                        : $this->newSession($i, $line['session'], $training, $quote);
                    foreach ($line['trainees'] as $j => $trainee) {
                        $personId = $trainee['person_id'] ?? $this->newTrainee($i, $j, $trainee['new_person'] ?? [], $training, $quote);
                        $this->enrol($i, $j, $dossier, $dossierLines[$i], $session, $personId);
                    }
                }

                $this->fund($dossier, $quote);

                return $dossier;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two attempts that passed the lock together (not expected): one dossier only.
            $existing = self::existing($quoteId);
            throw $existing === null ? $e : new DossierExists($existing);
        }
    }

    /**
     * « Créer le dossier » or « Voir le dossier » on the page of an accepted
     * quote (Crm\Public\QuoteLinks).
     *
     * @return array{label: string, href: string}|null
     */
    public function link(string $quoteId, User $user): ?array
    {
        $existing = self::existing($quoteId);
        if ($existing !== null) {
            $link = DossierExists::link($existing, $user);

            return $link['url'] === null ? null : ['label' => "Voir le dossier {$existing->number}", 'href' => $link['url']];
        }
        if (! $user->can(Permissions::DOSSIERS_UPDATE)) {
            return null;
        }
        $quote = $this->quotes->find($quoteId, $user);

        return $quote === null || ! SessionsVisibility::allowsDas($user, Permissions::DOSSIERS_UPDATE, $quote->das_id) ? null
            : ['label' => 'Créer le dossier', 'href' => route('dossiers.from-quote', $quote->id)];
    }

    private function find(string $quoteId, User $user, bool $lock = false): AcceptedQuoteData
    {
        return $this->quotes->find($quoteId, $user, $lock) ?? throw new NotFoundHttpException;
    }

    /**
     * The plan checked, per quote line: the session (an existing one, the
     * input of a new one, or null), the pricing mode and the trainees
     * (['person_id' => id] or ['new_person' => input]).
     *
     * @param  array<string, TrainingData>  $trainings
     * @param  array<string, mixed>  $plan
     * @return array<int, array{session: TrainingSession|array<string, mixed>|null, pricing_mode: ?string, trainees: list<array<string, mixed>>}>
     *
     * @throws ValidationException
     */
    private function validatedPlan(AcceptedQuoteData $quote, array $trainings, array $plan, User $user): array
    {
        $input = is_array($plan['lines'] ?? null) ? $plan['lines'] : [];
        $count = count($quote->lines);
        $wanted = false;
        foreach ($input as $line) {
            $wanted = $wanted || (is_array($line) && (self::filled($line['session_id'] ?? null) || is_array($line['new_session'] ?? null) || ! empty($line['trainees'])));
        }
        if ($wanted && ! $user->can(Permissions::SESSIONS_UPDATE)) {
            throw new UserFacingHttpException(403, self::NO_SESSIONS_RIGHT);
        }

        $validator = Validator::make(['lines' => $input], [
            'lines' => ['array', 'max:'.$count],
            'lines.*' => ['nullable', 'array'],
            'lines.*.session_id' => ['nullable', 'uuid'],
            'lines.*.new_session' => ['nullable', 'array'],
            'lines.*.pricing_mode' => ['nullable', Rule::in(array_keys(DossierLine::PRICING_MODES))],
            'lines.*.trainees' => ['nullable', 'array', 'max:'.self::MAX_TRAINEES],
        ], [
            'lines.array' => 'Plan invalide.',
            'lines.max' => 'Plan invalide : le devis compte '.$count.' lignes.',
            'lines.*.array' => 'Ligne invalide.',
            'lines.*.session_id.*' => 'Session introuvable.',
            'lines.*.new_session.*' => 'Nouvelle session invalide.',
            'lines.*.pricing_mode.*' => 'Mode de prix inconnu.',
            'lines.*.trainees.array' => 'Stagiaires invalides.',
            'lines.*.trainees.max' => 'Une ligne compte au plus '.self::MAX_TRAINEES.' stagiaires.',
        ]);

        $lines = [];
        $validator->after(function ($v) use ($input, $count, $quote, $trainings, $user, &$lines): void {
            foreach (array_keys($input) as $key) {
                if (! is_int($key) || $key < 0 || $key >= $count) {
                    $v->errors()->add('lines', 'Ligne du devis inconnue.');
                }
            }
            if ($v->errors()->has('lines')) {
                return;
            }
            $organisationId = $quote->account['type'] === 'organisation' ? $quote->account['id'] : null;
            for ($i = 0; $i < $count; $i++) {
                $line = is_array($input[$i] ?? null) ? $input[$i] : [];
                $training = $quote->lines[$i]['training_id'] === null ? null : ($trainings[$quote->lines[$i]['training_id']] ?? null);
                $sessionId = self::filled($line['session_id'] ?? null) ? $line['session_id'] : null;
                $newSession = is_array($line['new_session'] ?? null) ? $line['new_session'] : null;
                $trainees = is_array($line['trainees'] ?? null) ? array_values($line['trainees']) : [];
                $lines[$i] = ['session' => null, 'pricing_mode' => is_string($line['pricing_mode'] ?? null) && $training !== null ? $line['pricing_mode'] : null, 'trainees' => []];

                if ($training === null) {
                    if ($sessionId !== null || $newSession !== null || $trainees !== []) {
                        $v->errors()->add("lines.{$i}.session_id", 'Une ligne de frais ne reçoit ni session ni stagiaire.');
                    }

                    continue;
                }
                if ($sessionId !== null && $newSession !== null) {
                    $v->errors()->add("lines.{$i}.session_id", 'Choisissez une session existante ou une nouvelle session, pas les deux.');

                    continue;
                }
                if ($sessionId !== null && ! $v->errors()->has("lines.{$i}.session_id")) {
                    $session = EnrolmentOptions::openSessions([$training->id], $organisationId, $user)->find($sessionId);
                    if ($session === null) {
                        $v->errors()->add("lines.{$i}.session_id", 'Cette session n\'est pas ouverte à ce dossier (autre formation, intra d\'une autre entreprise, complète ou annulée).');
                    } else {
                        $lines[$i]['session'] = $session;
                    }
                }
                if ($newSession !== null) {
                    $kind = $newSession['kind'] ?? null;
                    if ($kind === SessionKind::Intra->value && $organisationId === null) {
                        $v->errors()->add("lines.{$i}.new_session.kind", 'Une session intra est réservée à une entreprise : ce devis est celui d\'un particulier.');
                    }
                    if (! SessionsVisibility::allowsDas($user, Permissions::SESSIONS_UPDATE, $training->das_id)) {
                        $v->errors()->add("lines.{$i}.new_session.kind", 'Cette formation n\'est pas dans vos DAS : vous ne pouvez pas en planifier de session.');
                    }
                    $lines[$i]['session'] = array_intersect_key($newSession, array_flip(self::NEW_SESSION_FIELDS));
                }
                if ($trainees !== [] && $sessionId === null && $newSession === null) {
                    $v->errors()->add("lines.{$i}.trainees", 'Choisissez la session de ces stagiaires.');

                    continue;
                }
                foreach ($trainees as $j => $trainee) {
                    $parsed = self::trainee($trainee);
                    if ($parsed === null) {
                        $v->errors()->add("lines.{$i}.trainees.{$j}", 'Stagiaire invalide.');
                    } else {
                        $lines[$i]['trainees'][] = $parsed;
                    }
                }
            }
        });
        $validator->validate();

        return $lines;
    }

    /**
     * A trainee of the plan: an id, {person_id} or {new_person}.
     *
     * @return array<string, mixed>|null
     */
    private static function trainee(mixed $trainee): ?array
    {
        if (is_string($trainee)) {
            $trainee = ['person_id' => $trainee];
        }
        if (! is_array($trainee)) {
            return null;
        }
        if (is_string($trainee['person_id'] ?? null) && Str::isUuid($trainee['person_id'])) {
            return ['person_id' => $trainee['person_id']];
        }

        return is_array($trainee['new_person'] ?? null) ? ['new_person' => $trainee['new_person']] : null;
    }

    /**
     * The input of DossierWriter: the quote's issuer, contractant, contact
     * (when live), the opportunity's DAS, its lines.
     *
     * @param  array<int, array{session: mixed, pricing_mode: ?string, trainees: list<array<string, mixed>>}>  $lines
     * @return array<string, mixed>
     */
    private function dossierData(AcceptedQuoteData $quote, array $lines): array
    {
        $contact = $quote->contact_person_id === null ? null : $this->fiches->person($quote->contact_person_id);

        return [
            'company_id' => $quote->company_id,
            'account_organisation_id' => $quote->account['type'] === 'organisation' ? $quote->account['id'] : null,
            'account_person_id' => $quote->account['type'] === 'person' ? $quote->account['id'] : null,
            'contact_person_id' => $contact['id'] ?? null,
            'das_id' => $quote->das_id,
            'lines' => array_map(fn (array $l, int $i) => [
                'kind' => $l['training_id'] === null ? DossierLine::FEE : DossierLine::TRAINING,
                'training_id' => $l['training_id'],
                'label' => $l['label'],
                'quantity' => $l['quantity'],
                'unit' => $l['unit'],
                'unit_price' => Amounts::decimal($l['unit_price_cents']),
                'discount_percent' => $l['discount_percent'],
                'pricing_mode' => $l['training_id'] === null ? null : $lines[$i]['pricing_mode'],
            ], $quote->lines, array_keys($quote->lines)),
        ];
    }

    /**
     * A new session of the line's training: intra and individual ones for
     * the contractant entreprise, planned.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function newSession(int $i, array $input, TrainingData $training, AcceptedQuoteData $quote): TrainingSession
    {
        $organisationId = $quote->account['type'] === 'organisation' ? $quote->account['id'] : null;
        $kind = $input['kind'] ?? null;

        return self::prefixed("lines.{$i}.new_session.", fn () => $this->sessions->create([
            ...$input,
            'training_id' => $training->id,
            'organisation_id' => in_array($kind, [SessionKind::Intra->value, SessionKind::Individual->value], true) ? $organisationId : null,
            'responsible_user_id' => null,
            'status' => SessionStatus::Planned->value,
            'notes' => null,
        ]));
    }

    /**
     * A trainee created as an ordinary person of the CRM, given the DAS of
     * the training (and, with « employee », employed by the contractant).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function newTrainee(int $i, int $j, array $input, TrainingData $training, AcceptedQuoteData $quote): string
    {
        $employer = $quote->account['type'] === 'organisation' && filter_var($input['employee'] ?? false, FILTER_VALIDATE_BOOLEAN) ? $quote->account['id'] : null;
        try {
            return $this->trainees->create([
                ...array_intersect_key($input, array_flip(self::NEW_PERSON_FIELDS)),
                'organisation_id' => $employer,
                'das_ids' => [$training->das_id],
                'confirm_duplicate' => filter_var($input['confirm_duplicate'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);
        } catch (DuplicateTrainee $e) {
            $names = implode(', ', array_map(fn (array $c) => trim(($c['first_name'] ?? '').' '.$c['last_name']).($c['organisation_label'] ? " ({$c['organisation_label']})" : ''), array_slice($e->candidates, 0, 5)));
            throw ValidationException::withMessages(["lines.{$i}.trainees.{$j}" => "Des personnes similaires existent déjà : {$names}. Choisissez-la dans la recherche, ou cochez « Créer quand même »."]);
        } catch (ValidationException $e) {
            throw self::rekeyed($e, "lines.{$i}.trainees.{$j}.");
        }
    }

    /**
     * @throws ValidationException
     */
    private function enrol(int $i, int $j, Dossier $dossier, DossierLine $line, TrainingSession $session, string $personId): void
    {
        try {
            $this->enrolments->enrol($dossier, $line, $session, $personId);
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $key => $errors) {
                $messages[$key === 'person_id' ? "lines.{$i}.trainees.{$j}" : "lines.{$i}.session_id"] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * The whole price, « À demander », paid by the contractant: an entreprise
     * as « Entreprise », a particulier as « Personnel » (items of « Types de
     * financeur »; none when the organisme removed it, or for a free dossier).
     */
    private function fund(Dossier $dossier, AcceptedQuoteData $quote): void
    {
        $total = $dossier->load('lines')->priceCents();
        $organisation = $quote->account['type'] === 'organisation';
        $type = ReferenceItem::query()->where('list', ReferenceList::FundingType)->where('code', $organisation ? 'entreprise' : 'personnel')
            ->where('is_active', true)->value('id');
        if ($total <= 0 || ! is_string($type)) {
            return;
        }
        $this->fundings->create($dossier, [
            'funder_organisation_id' => $organisation ? $quote->account['id'] : null,
            'funder_person_id' => $organisation ? null : $quote->account['id'],
            'funding_type_id' => $type,
            'target' => 'dossier',
            'amount' => Amounts::decimal($total),
            'status' => FundingStatus::ToRequest->value,
        ]);
    }

    /**
     * Runs $write, its validation errors keyed under $prefix.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     *
     * @throws ValidationException
     */
    private static function prefixed(string $prefix, callable $write): mixed
    {
        try {
            return $write();
        } catch (ValidationException $e) {
            throw self::rekeyed($e, $prefix);
        }
    }

    /**
     * The errors of $e, keyed under $prefix.
     */
    private static function rekeyed(ValidationException $e, string $prefix): ValidationException
    {
        $messages = [];
        foreach ($e->errors() as $key => $errors) {
            $messages[$prefix.$key] = $errors;
        }

        return ValidationException::withMessages($messages);
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** « 3.00 » → « 3 », « 2.50 » → « 2,5 ». */
    private static function french(string $decimal): string
    {
        if (str_contains($decimal, '.')) {
            $decimal = rtrim(rtrim($decimal, '0'), '.');
        }

        return str_replace('.', ',', $decimal);
    }
}
