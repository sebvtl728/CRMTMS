<?php

namespace App\Modules\Questionnaires\Templates;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Questionnaires\Settings\ColdDelay;
use App\Modules\Questionnaires\Surveys\SurveyTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration › Questionnaires (questionnaires.manage): the templates
 * and the delay of the cold questionnaire.
 */
class SurveyTemplateController extends Controller
{
    public function __construct(private readonly SurveyTemplates $templates) {}

    public function index(Request $request): Response
    {
        DefaultTemplates::ensure();

        return Inertia::render('admin/questionnaires/index', [
            'templates' => SurveyTemplate::query()->orderBy('kind')->orderBy('audience')->orderBy('name')->get()
                ->map(fn (SurveyTemplate $t) => [
                    'id' => $t->id, 'name' => $t->name, 'kind' => $t->kind, 'audience' => $t->audience,
                    'active' => $t->active, 'is_default' => $t->is_default, 'questions_count' => count($t->questions),
                ])->values(),
            'cold_months' => ColdDelay::months(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/questionnaires/form', ['template' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->templates->create($request->all(), $this->user($request));

        return to_route('admin.questionnaires.index')->with('status', 'Modèle créé.');
    }

    public function edit(SurveyTemplate $surveyTemplate): Response
    {
        return Inertia::render('admin/questionnaires/form', ['template' => [
            'id' => $surveyTemplate->id, 'name' => $surveyTemplate->name, 'kind' => $surveyTemplate->kind,
            'audience' => $surveyTemplate->audience, 'active' => $surveyTemplate->active, 'questions' => $surveyTemplate->questions,
        ]]);
    }

    public function update(Request $request, SurveyTemplate $surveyTemplate): RedirectResponse
    {
        $this->templates->update($surveyTemplate, $request->all(), $this->user($request));

        return to_route('admin.questionnaires.index')->with('status', 'Modèle enregistré.');
    }

    public function destroy(SurveyTemplate $surveyTemplate): RedirectResponse
    {
        $this->templates->delete($surveyTemplate);

        return to_route('admin.questionnaires.index')->with('status', 'Modèle supprimé.');
    }

    public function makeDefault(Request $request, SurveyTemplate $surveyTemplate): RedirectResponse
    {
        $this->templates->setDefault($surveyTemplate, $this->user($request));

        return to_route('admin.questionnaires.index')->with('status', 'Modèle par défaut changé.');
    }

    public function updateDelay(Request $request, AuditRecorder $audit, TenantContext $tenants): RedirectResponse
    {
        $months = (int) $request->validate(
            ['months' => ['required', 'integer', 'between:0,'.ColdDelay::MAX]],
            ['months.*' => 'Le délai du questionnaire à froid est un nombre de mois de 0 à 24.'],
        )['months'];
        [$old, $new] = ColdDelay::set($months);
        if ($old !== $new) {
            $audit->record('settings.survey_cold_delay_changed', $tenants->current(), ['months' => $old], ['months' => $new]);
        }

        return to_route('admin.questionnaires.index')->with('status', 'Délai du questionnaire à froid enregistré.');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
