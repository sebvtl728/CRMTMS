<?php

namespace App\Modules\Admissions\Booklet;

use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * RGPD of the booklet follow-up. The apprentice anonymised (with his
 * candidature): the texts, observations, visit points, signature drawings,
 * addresses, browsers and frozen names go, the links close and lose their
 * e-mail; the A / ECA / NA marks stay (no personal data). A tutor
 * anonymised: his frozen name, drawing, address and browser and the e-mail
 * of his links go.
 */
final class BookletAnonymisation
{
    public static function forCandidature(string $candidatureId): void
    {
        $months = BookletMonth::query()->where('candidature_id', $candidatureId)->get();
        self::deleteDrawings($months->pluck('apprentice_signature_path')->merge($months->pluck('tutor_signature_path'))->all());
        BookletMonth::query()->where('candidature_id', $candidatureId)->toBase()->update([
            'apprentice_entries' => null, 'observations' => null,
            'apprentice_signature_path' => null, 'apprentice_signer_name' => null, 'apprentice_ip' => null, 'apprentice_agent' => null,
            'tutor_signature_path' => null, 'tutor_signer_name' => null, 'tutor_ip' => null, 'tutor_agent' => null,
            'updated_at' => now(),
        ]);
        BookletVisit::withTrashed()->where('candidature_id', $candidatureId)->toBase()->update(['points' => null, 'updated_at' => now()]);
        BookletLink::query()->where('candidature_id', $candidatureId)->toBase()->update([
            'email' => null, 'signer_name' => null, 'code_hash' => null, 'closed_at' => DB::raw('coalesce(closed_at, now())'), 'updated_at' => now(),
        ]);
    }

    /**
     * The tutors among these people (their links and signatures).
     *
     * @param  list<string>  $personIds
     */
    public static function forTutors(array $personIds): void
    {
        $linkIds = BookletLink::query()->whereIn('person_id', $personIds)->where('signer', BookletLink::TUTOR)->pluck('id')->all();
        if ($linkIds === []) {
            return;
        }
        $months = BookletMonth::query()->whereIn('tutor_link_id', $linkIds)->get();
        self::deleteDrawings($months->pluck('tutor_signature_path')->all());
        BookletMonth::query()->whereIn('tutor_link_id', $linkIds)->toBase()->update([
            'tutor_signature_path' => null, 'tutor_signer_name' => null, 'tutor_ip' => null, 'tutor_agent' => null, 'updated_at' => now(),
        ]);
        BookletLink::query()->whereKey($linkIds)->toBase()->update([
            'email' => null, 'signer_name' => null, 'code_hash' => null, 'closed_at' => DB::raw('coalesce(closed_at, now())'), 'updated_at' => now(),
        ]);
    }

    /**
     * What the booklet keeps of a candidature, for the RGPD export.
     *
     * @return array{mois: list<array<string, mixed>>, evaluations: list<array{cote: string, niveau: string}>, visites: list<array{date: string, points: ?string}>}
     */
    public static function export(string $candidatureId): array
    {
        return [
            'mois' => BookletMonth::query()->where('candidature_id', $candidatureId)->orderBy('month')->orderBy('place')->get()
                ->map(fn (BookletMonth $m) => [
                    'mois' => $m->month->format('Y-m'),
                    'page' => $m->place === BookletMonth::COMPANY ? 'entreprise' : 'centre',
                    'travaux_apprenti' => $m->apprentice_entries,
                    'observations' => $m->observations,
                    'signe_par_apprenti_le' => $m->apprentice_signed_at?->toIso8601String(),
                    'signe_sur_papier_apprenti' => $m->apprentice_paper,
                    'signe_par_tuteur_le' => $m->tutor_signed_at?->toIso8601String(),
                    'signe_sur_papier_tuteur' => $m->tutor_paper,
                    'vise_le' => $m->visa_at?->toIso8601String(),
                ])->values()->all(),
            'evaluations' => BookletEvaluation::query()->where('candidature_id', $candidatureId)->orderBy('side')->get()
                ->map(fn (BookletEvaluation $e) => ['cote' => $e->side === 'centre' ? 'centre' : 'entreprise', 'niveau' => $e->level])->values()->all(),
            'visites' => BookletVisit::query()->where('candidature_id', $candidatureId)->orderBy('visited_on')->get()
                ->map(fn (BookletVisit $v) => ['date' => $v->visited_on->toDateString(), 'points' => $v->points])->values()->all(),
        ];
    }

    /**
     * @param  list<string|null>  $paths
     */
    private static function deleteDrawings(array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths)));
        if ($paths !== []) {
            Storage::disk(TenantStorage::DISK)->delete($paths);
        }
    }
}
