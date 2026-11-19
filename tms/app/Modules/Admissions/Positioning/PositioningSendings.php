<?php

namespace App\Modules\Admissions\Positioning;

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\OnlineTests\OnlineTests;
use App\Modules\Catalogue\Brochures;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Mail\MailTemplateDefinition;
use App\Modules\Core\Mail\MailTemplateRegistry;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantUrl;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sends the positioning tests of the library to a candidate: the tests
 * offered are those of the training or the DAS of the candidature
 * (Brochures::positioningTests); a sending records the e-mail, the versions
 * and a personal upload link valid LINK_DAYS days (end of the Paris day),
 * revokes the previous link, fills « Envoyé le » when empty and moves the
 * stage to « Test envoyé » from « Identifié » only (never back).
 */
final class PositioningSendings
{
    public const TEMPLATE = 'positioning_test';

    public const LINK_DAYS = 14;

    /** Placeholder of the link in the message, filled at sending time. */
    public const LINK = '{lien}';

    public static function registerTemplate(): void
    {
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::TEMPLATE,
            label: 'Envoi d\'un test de positionnement',
            description: 'Proposé dans la fenêtre « Envoyer le test » d\'une candidature, toujours modifiable avant l\'envoi. Les tests choisis sont joints ; {lien} est le lien personnel où le candidat dépose sa copie.',
            subject: 'Votre test de positionnement - {organisme}',
            body: "Bonjour {prenom},\n\nDans le cadre de votre candidature à la formation « {formation} », vous trouverez ci-joint votre test de positionnement.\n\nUne fois complété, déposez votre copie (PDF ou photo) sur votre lien personnel, avant le {date_limite} :\n{lien}\n\nCordialement,\n{organisme}",
            variables: [
                'prenom' => ['prénom du candidat', 'Léa'],
                'nom' => ['nom du candidat', 'Durand'],
                'formation' => ['formation visée', 'CAP Cuisine'],
                'lien' => ['lien personnel de dépôt de la copie', 'https://tms.exemple.fr/test-positionnement/…'],
                'date_limite' => ['dernier jour du lien', '03/12/2026'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
            ],
            required: ['lien'],
        ));
    }

    /**
     * The tests offered for this candidature.
     *
     * @return list<array{brochure_id: string, version_id: string, title: string, original_name: string, size: int}>
     */
    public function offered(Candidature $c): array
    {
        return app(Brochures::class)->positioningTests($c->training_id, $c->das_id);
    }

    /** The day the link of a sending made now stops working (Paris). */
    public static function deadline(?CarbonImmutable $from = null): CarbonImmutable
    {
        return ($from ?? CarbonImmutable::now('Europe/Paris'))->setTimezone('Europe/Paris')->addDays(self::LINK_DAYS)->endOfDay();
    }

    /**
     * What the window proposes: the person's e-mail and the template filled
     * in, {lien} kept for the link.
     *
     * @return array{to: string, subject: string, body: string}
     */
    public function defaults(Candidature $c): array
    {
        $person = app(Fiches::class)->personLabels([$c->person_id])[$c->person_id] ?? null;
        $profile = app(Trainees::class)->profile($c->person_id);
        $training = $c->training_id !== null ? app(Trainings::class)->find($c->training_id) : null;
        $text = app(MailTemplates::class)->render(self::TEMPLATE, [
            'prenom' => (string) ($person['first_name'] ?? ''),
            'nom' => (string) ($person['last_name'] ?? ''),
            'formation' => (string) ($training->title ?? ''),
            'lien' => self::LINK,
            'date_limite' => self::deadline()->format('d/m/Y'),
            'organisme' => (string) (app(TenantContext::class)->current()->name ?? ''),
        ]);

        return ['to' => (string) ($profile['email'] ?? ''), 'subject' => $text['subject'], 'body' => $text['body']];
    }

    /**
     * Records and queues a sending.
     *
     * @param  array<string, mixed>  $data  version_ids, to, subject, body
     */
    public function send(Candidature $candidature, array $data, User $by): PositioningSending
    {
        $validator = Validator::make($data, [
            'version_ids' => ['sometimes', 'nullable', 'array', 'max:10'],
            'version_ids.*' => ['string', 'distinct'],
            'to' => ['required', 'email:rfc', 'max:254'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ], [
            'version_ids.required' => 'Choisissez au moins un test proposé pour cette candidature.',
            'version_ids.min' => 'Choisissez au moins un test proposé pour cette candidature.',
            'to.required' => 'Indiquez l\'adresse du candidat.',
            'to.email' => 'Adresse e-mail invalide.',
            'required' => 'Ce champ est obligatoire.',
            'max' => 'Ce champ est trop long.',
            'distinct' => 'Ce test est en double.',
        ]);
        $validator->after(function ($v) use ($data): void {
            if (is_string($data['body'] ?? null) && ! str_contains($data['body'], self::LINK)) {
                $v->errors()->add('body', 'Le message doit contenir {lien} (le lien du candidat).');
            }
        });
        $values = $validator->validate();
        [$ids, $onlineVersion] = $this->chosen($candidature, $data, defaultAll: false);
        if (PositioningAttachments::size($ids) > PositioningAttachments::MAX_BYTES) {
            throw ValidationException::withMessages(['version_ids' => 'Les tests choisis dépassent 20 Mo : envoyez-en moins à la fois.']);
        }

        [$sending, $token] = $this->record($candidature, $ids, $by, [
            'online_test_version_id' => $onlineVersion,
            'email' => mb_strtolower(trim($values['to'])),
            'subject' => trim($values['subject']),
            'message' => $values['body'],
        ]);
        SendPositioningTest::dispatch($sending->id, $token)->afterCommit();

        return $sending;
    }

    /**
     * « Générer le lien »: a link to copy and hand to the candidate, no
     * e-mail; the same rules as a sending (tests offered, previous link
     * revoked, date and stage).
     *
     * @param  array<string, mixed>  $data  version_ids (default: every test offered)
     */
    public function link(Candidature $candidature, array $data, User $by): PositioningSending
    {
        [$ids, $onlineVersion] = $this->chosen($candidature, $data, defaultAll: true);

        return $this->record($candidature, $ids, $by, ['email_status' => PositioningSending::NO_EMAIL, 'online_test_version_id' => $onlineVersion])[0];
    }

    /**
     * The PDF tests and/or the online test chosen for a link, checked
     * against those offered for the candidature (at least one).
     *
     * @param  array<string, mixed>  $data  version_ids, online_test_id
     * @return array{0: list<string>, 1: string|null} versions of PDF tests, version of the online test
     */
    private function chosen(Candidature $candidature, array $data, bool $defaultAll): array
    {
        $onlineVersion = null;
        if (is_string($data['online_test_id'] ?? null) && $data['online_test_id'] !== '') {
            $test = collect(app(OnlineTests::class)->offered($candidature->training_id, $candidature->das_id))->firstWhere('id', $data['online_test_id']);
            if ($test === null) {
                throw ValidationException::withMessages(['online_test_id' => 'Choisissez un test en ligne publié pour cette candidature.']);
            }
            $onlineVersion = $test['version_id'];
        }
        $offered = array_column($this->offered($candidature), 'version_id');
        $ids = is_array($data['version_ids'] ?? null) ? array_values($data['version_ids']) : ($defaultAll && $onlineVersion === null ? $offered : []);
        if (($ids === [] && $onlineVersion === null) || array_diff($ids, $offered) !== [] || count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['version_ids' => 'Choisissez au moins un test proposé pour cette candidature.']);
        }

        return [array_map('strval', $ids), $onlineVersion];
    }

    /**
     * The working link of the candidature (the newest one, neither revoked,
     * used nor expired), to copy; null without one.
     *
     * @return array{url: string, expires_on: string}|null
     */
    public static function activeLink(Candidature $c): ?array
    {
        $sending = PositioningSending::query()->where('candidature_id', $c->id)->whereNull('revoked_at')->whereNull('submitted_at')
            ->where('expires_at', '>', CarbonImmutable::now())->whereNotNull('token_encrypted')->latest('created_at')->first();
        if ($sending === null || $sending->token_encrypted === null) {
            return null;
        }

        return [
            'url' => TenantUrl::route('positioning.show', $sending->token_encrypted),
            'expires_on' => $sending->expires_at->setTimezone('Europe/Paris')->format('d/m/Y'),
        ];
    }

    /**
     * Records a sending (a new link) under the candidature's lock: previous
     * link revoked, versions, « Envoyé le » and the stage.
     *
     * @param  list<string>  $versionIds
     * @param  array<string, mixed>  $values  email, subject, message, or email_status none
     * @return array{0: PositioningSending, 1: string} the sending and its plain token
     */
    private function record(Candidature $candidature, array $versionIds, User $by, array $values): array
    {
        $token = bin2hex(random_bytes(32));
        $sending = DB::transaction(function () use ($candidature, $versionIds, $by, $values, $token): PositioningSending {
            /** @var Candidature $locked */
            $locked = Candidature::query()->lockForUpdate()->findOrFail($candidature->getKey());
            $now = CarbonImmutable::now();
            PositioningSending::query()->where('candidature_id', $locked->id)->whereNull('revoked_at')->whereNull('submitted_at')
                ->toBase()->update(['revoked_at' => $now, 'updated_at' => $now]);

            $sending = PositioningSending::query()->create([
                ...$values,
                'candidature_id' => $locked->id,
                'token_hash' => PositioningSending::hashToken($token),
                'token_encrypted' => $token,
                'expires_at' => self::deadline(),
                'created_by' => $by->id,
            ]);
            foreach ($versionIds as $i => $versionId) {
                DB::table('positioning_sending_versions')->insert([
                    'tenant_id' => $sending->tenant_id, 'positioning_sending_id' => $sending->id, 'brochure_version_id' => $versionId, 'position' => $i,
                ]);
            }

            $changes = [];
            if ($locked->positioning_sent_on === null) {
                $changes['positioning_sent_on'] = CarbonImmutable::now('Europe/Paris')->toDateString();
            }
            if ($locked->status === CandidatureStatus::Identified) {
                $changes['status'] = CandidatureStatus::PositioningSent->value;
            }
            if ($changes !== []) {
                $locked->forceFill($changes)->save();
            }

            return $sending;
        });

        return [$sending, $token];
    }

    /**
     * The sendings of a candidature for its page, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forPage(Candidature $c): array
    {
        $titles = [];
        $sendings = PositioningSending::query()->where('candidature_id', $c->id)->orderByDesc('created_at')->orderByDesc('id')->get();
        $versions = DB::table('positioning_sending_versions')->whereIn('positioning_sending_id', $sendings->pluck('id'))->orderBy('position')->get()->groupBy('positioning_sending_id');
        $sent = app(Brochures::class)->sent($versions->flatten()->pluck('brochure_version_id')->map(fn ($id) => (string) $id)->unique()->values()->all());

        return $sendings->map(fn (PositioningSending $s) => [
            'id' => $s->id,
            'created_at' => $s->created_at?->toIso8601String(),
            'email' => $s->email,
            'tests' => collect($versions->get($s->id, []))->map(fn ($v) => $sent[(string) $v->brochure_version_id]['title'] ?? '-')->values()->all(),
            'email_status' => $s->email_status,
            'email_error' => $s->email_error,
            'expires_at' => $s->expires_at->toIso8601String(),
            'submitted_at' => $s->submitted_at?->toIso8601String(),
            'link' => match (true) {
                $s->submitted_at !== null => 'Copie déposée',
                $s->revoked_at !== null => 'Remplacé par un envoi plus récent',
                ! $s->expires_at->isFuture() => 'Expiré',
                default => 'Actif jusqu\'au '.$s->expires_at->setTimezone('Europe/Paris')->format('d/m/Y'),
            },
        ])->values()->all();
    }
}
