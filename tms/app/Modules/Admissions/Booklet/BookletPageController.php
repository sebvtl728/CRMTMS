<?php

namespace App\Modules\Admissions\Booklet;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * « Livrets d'apprentissage »: the apprentices the user follows (right
 * admissions.booklet.manage on its scope) and the follow-up of one of them
 * (monthly pages, grid, visits). Shows nothing of the candidature beyond
 * the apprentice, the employer, the tutor, the training and the contract.
 */
final class BookletPageController extends Controller
{
    public const TABS = ['suivi', 'grille', 'visites'];

    public function index(Request $request, Fiches $fiches, Trainings $trainings): InertiaResponse
    {
        /** @var User $user */
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $query = BookletAccess::candidatures(Candidature::query(), $user)->orderByDesc('created_at');
        $candidatures = $query->get(['id', 'number', 'person_id', 'training_id']);

        $people = $fiches->personLabels($candidatures->pluck('person_id')->unique()->values()->all());
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $candidatures = $candidatures->filter(fn (Candidature $c) => str_contains(mb_strtolower($people[$c->person_id]['label'] ?? ''), $needle))->values();
        }
        $page = $candidatures->forPage(max(1, (int) $request->query('page', 1)), 50)->values();
        $contracts = AlternanceContract::query()->whereIn('candidature_id', $page->pluck('id'))->get()->keyBy('candidature_id');
        $employers = $fiches->organisationLabels($contracts->pluck('employer_organisation_id')->unique()->values()->all());
        $titles = $trainings->findMany($page->pluck('training_id')->filter()->unique()->values()->all());

        return Inertia::render('admissions/booklet/index', [
            'rows' => $page->map(fn (Candidature $c) => [
                'id' => $c->id,
                'apprentice' => $people[$c->person_id]['label'] ?? 'Apprenti',
                'employer' => $employers[$contracts[$c->id]->employer_organisation_id ?? '']['label'] ?? null,
                'training' => $c->training_id === null ? null : ($titles[$c->training_id]->title ?? null),
                'pending' => BookletMonths::pending($c),
            ])->all(),
            'total' => $candidatures->count(),
            'q' => $q,
        ]);
    }

    public function show(Request $request, Candidature $bookletCandidature, Fiches $fiches, Trainings $trainings, BookletGrid $grid): InertiaResponse
    {
        $c = $bookletCandidature;
        $contract = AlternanceContract::query()->where('candidature_id', $c->id)->firstOrFail();
        $person = $fiches->contactCard('person', $c->person_id);
        $tutor = $contract->tutor_person_id === null ? null : $fiches->contactCard('person', $contract->tutor_person_id);
        $employer = $fiches->contactCard('organisation', $contract->employer_organisation_id);
        $tab = in_array($request->query('onglet'), self::TABS, true) ? $request->query('onglet') : 'suivi';

        return Inertia::render('admissions/booklet/show', [
            'candidature' => [
                'id' => $c->id,
                'number' => $c->number,
                'apprentice' => $person['label'] ?? 'Apprenti',
                'apprentice_email' => $person['email'] ?? null,
                'employer' => $employer['label'] ?? null,
                'tutor' => $tutor['label'] ?? null,
                'tutor_email' => $tutor['email'] ?? null,
                'training' => $c->training_id === null ? null : $trainings->find($c->training_id)?->title,
                'contract' => ['starts_on' => $contract->starts_on->format('d/m/Y'), 'ends_on' => $contract->ends_on->format('d/m/Y')],
            ],
            'months' => array_map(fn (array $m) => [...$m, 'pages' => array_map(fn (array $p) => [
                ...$p,
                'apprentice' => self::withoutDrawing($p['apprentice']),
                'tutor' => $p['tutor'] === null ? null : self::withoutDrawing($p['tutor']),
            ], $m['pages'])], BookletMonths::months($c)),
            'grid' => $grid->tree($c),
            'visits' => BookletVisits::list($c),
            'tab' => $tab,
        ]);
    }

    /** The booklet PDF for whoever follows the booklet (trainers included). */
    public function pdf(Candidature $bookletCandidature, BookletPdf $booklet): Response
    {
        return response($booklet->pdf($bookletCandidature), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.BookletPdf::fileName($bookletCandidature).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  array{status: string, at: ?string, name: ?string, drawing: ?string}  $state
     * @return array{status: string, at: ?string, name: ?string}
     */
    private static function withoutDrawing(array $state): array
    {
        unset($state['drawing']);

        return $state;
    }
}
