<?php

namespace App\Modules\Crm\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Core\Exports\XlsxExporter;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports of the « Tableaux de bord » page (lot 1e, crm.export): a CSV for
 * Excel (« ; », UTF-8 with BOM, French amounts) and a PDF of the cards with
 * their filters (PdfRenderer), both journalled.
 */
class DashboardExportController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function csv(Request $request): StreamedResponse
    {
        [$filters, $cards, $user] = $this->cards($request);
        $this->audit->record('crm.dashboard.exported', null, [], ['format' => 'csv', ...$filters->toArray()], $user);
        $period = $filters->label();

        return response()->streamDownload(function () use ($cards, $period): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            $row = fn (array $cells) => fwrite($out, implode(';', array_map(fn (?string $c) => self::cell($c), $cells))."\r\n");
            $row(['Indicateur', 'Formule', 'Période', 'Valeur', 'Période précédente', 'Objectif']);
            foreach ($cards as $card) {
                $row([$card['label'], $card['formula'], $period, self::format($card['value'], $card['unit']), self::format($card['previous'], $card['unit']),
                    $card['target_cents'] === null ? '' : self::format($card['target_cents'], IndicatorValue::CENTS)]);
            }
            fclose($out);
        }, 'tableaux-de-bord-'.$filters->from->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pdf(Request $request, PdfRenderer $renderer): Response
    {
        [$filters, $cards, $user] = $this->cards($request);
        $this->audit->record('crm.dashboard.exported', null, [], ['format' => 'pdf', ...$filters->toArray()], $user);
        $label = fn (?string $id) => $id === null ? null : ReferenceItem::withTrashed()->whereKey($id)->value('label');
        $html = view('documents.crm-dashboard', [
            'tab' => $filters->tab === Indicator::MONTH ? 'Mois / direction' : 'Semaine',
            'period' => $filters->label(),
            'previous' => $filters->previous()->label(),
            'filters' => array_filter([
                'DAS' => $label($filters->das),
                'Cible' => match ($filters->target) {
                    'organisation' => 'Entreprises', 'person' => 'Particuliers', default => null
                },
                'Commercial' => $filters->responsible === null ? null : User::query()->whereKey($filters->responsible)->value('name'),
            ]),
            'cards' => array_map(fn (array $c) => [
                'label' => $c['label'], 'formula' => $c['formula'], 'value' => self::format($c['value'], $c['unit']),
                'previous' => self::format($c['previous'], $c['unit']), 'note' => $c['note'],
                'target' => $c['target_cents'] === null ? null : self::format($c['target_cents'], IndicatorValue::CENTS),
                'breakdown' => array_map(fn (array $r) => ['label' => $r['label'], 'value' => self::format($r['value'], $c['unit'])], $c['breakdown'] ?? []),
            ], $cards),
            'printed_on' => CarbonImmutable::now('Europe/Paris')->format('d/m/Y à H:i'),
        ])->render();

        return response($renderer->fromHtml($html), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="tableaux-de-bord-'.$filters->from->toDateString().'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** « 1 470,00 € », « 33,3 % », « 30 j », « 12 »; empty when null. */
    public static function format(int|float|null $value, string $unit): string
    {
        if ($value === null) {
            return '';
        }

        return match ($unit) {
            IndicatorValue::CENTS => number_format($value / 100, 2, ',', ' ').' €',
            IndicatorValue::PERCENT => str_replace('.', ',', (string) round((float) $value, 1)).' %',
            IndicatorValue::DAYS => str_replace('.', ',', (string) round((float) $value, 1)).' j',
            default => number_format($value, is_float($value) && floor($value) != $value ? 1 : 0, ',', ' '),
        };
    }

    /** @return array{0: DashboardFilters, 1: list<array<string, mixed>>, 2: User} */
    private function cards(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $filters = DashboardFilters::from($request->query(), CarbonImmutable::now(), (string) $request->query('tab', Indicator::WEEK));

        return [$filters, Indicators::for($filters->tab, $filters, $user), $user];
    }

    private static function cell(?string $value): string
    {
        $value = (string) XlsxExporter::neutralise($value);

        return preg_match('/[;"\r\n]/', $value) === 1 ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
