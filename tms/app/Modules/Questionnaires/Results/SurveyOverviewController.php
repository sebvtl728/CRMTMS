<?php

namespace App\Modules\Questionnaires\Results;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Exports\XlsxExporter;
use App\Modules\Core\Users\User;
use App\Modules\Questionnaires\Surveys\SurveyScoring;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Qualité › Questionnaires (questionnaires.view): the results of every
 * session over a period, and their CSV export (« ; », UTF-8 with BOM).
 */
class SurveyOverviewController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $rows = SurveyOverview::rows($filters);
        $trainings = app(Trainings::class)->findMany(array_values(array_unique(array_column(SurveyOverview::rows(['from' => $filters['from'], 'to' => $filters['to']]), 'training_id'))));

        return Inertia::render('qualite/questionnaires', [
            'filters' => $filters,
            'rows' => array_map(fn (array $r) => [...array_diff_key($r, ['scores' => true]), 'average_label' => SurveyScoring::label($r['average'])], $rows),
            'totals' => [...($t = SurveyOverview::totals($rows)), 'average_label' => SurveyScoring::label($t['average'])],
            'training_options' => collect($trainings)->map(fn ($t, $id) => ['value' => (string) $id, 'label' => (string) $t->title])->sortBy('label')->values(),
        ]);
    }

    public function export(Request $request, AuditRecorder $audit): StreamedResponse
    {
        $filters = $this->filters($request);
        /** @var User $user */
        $user = $request->user();
        $rows = SurveyOverview::rows($filters);
        $audit->record('questionnaires.results.exported', null, [], ['format' => 'csv', ...$filters], $user);
        $percent = fn (?int $v) => $v === null ? '' : "{$v} %";

        return response()->streamDownload(function () use ($rows, $percent): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            $line = fn (array $cells) => fwrite($out, implode(';', array_map(fn ($c) => self::cell((string) $c), $cells))."\r\n");
            $line(['Session', 'Fin', 'Type', 'Destinataires', 'Invités', 'Réponses', 'Taux de réponse', 'Satisfaction moyenne', 'Satisfaits']);
            foreach ($rows as $r) {
                $line([$r['session_label'], CarbonImmutable::parse($r['ends_on'])->format('d/m/Y'), $r['kind'] === 'hot' ? 'À chaud' : 'À froid',
                    $r['audience'] === 'client' ? 'Entreprise' : 'Stagiaires', $r['invited'], $r['answered'], $percent($r['response_rate']),
                    $r['average'] === null ? '' : SurveyScoring::label($r['average']), $percent($r['satisfaction_rate'])]);
            }
            fclose($out);
        }, 'questionnaires-'.$filters['from'].'-'.$filters['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{from: string, to: string, kind: ?string, audience: ?string, training_id: ?string} */
    private function filters(Request $request): array
    {
        [$from, $to] = SurveyOverview::period($request->only(['from', 'to']));
        $pick = fn (string $key, array $allowed) => in_array($request->query($key), $allowed, true) ? $request->query($key) : null;
        $training = $request->query('training_id');

        return [
            'from' => $from, 'to' => $to,
            'kind' => $pick('kind', ['hot', 'cold']),
            'audience' => $pick('audience', ['trainee', 'client']),
            'training_id' => is_string($training) && preg_match('/^[0-9a-f-]{36}$/', $training) === 1 ? $training : null,
        ];
    }

    private static function cell(string $value): string
    {
        $value = (string) XlsxExporter::neutralise($value);

        return preg_match('/[;"\r\n]/', $value) === 1 ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
