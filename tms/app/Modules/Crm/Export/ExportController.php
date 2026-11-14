<?php

namespace App\Modules\Crm\Export;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Exports\ExportResource;
use App\Modules\Core\Exports\XlsxExporter;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\OpportunityListQuery;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Quotes\QuoteListQuery;
use App\Modules\Crm\Search\CrmResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel export of « Entreprises », « Personnes », « Opportunités » and « Devis »
 * (crm.export): the same filters, visibility and sort as the list, any
 * number of columns among the ones the resource offers. Audited with the filters and the row count.
 */
class ExportController extends Controller
{
    public function __construct(private readonly XlsxExporter $exporter, private readonly AuditRecorder $audit) {}

    public function organisations(Request $request): StreamedResponse
    {
        return $this->runResource($request, CrmResource::Organisations);
    }

    public function people(Request $request): StreamedResponse
    {
        return $this->runResource($request, CrmResource::People);
    }

    /** Opportunities: the filters, visibility and sort of their list. */
    public function opportunities(Request $request): StreamedResponse
    {
        $user = $this->user($request);
        $filters = OpportunityListQuery::filters($request->query());

        /** @var Builder<Model> $query */
        $query = OpportunityListQuery::build($filters, $user);

        // A search term may be the name of a particulier.
        return $this->run($request, new OpportunityExport, 'crm.opportunities', $filters, $query, true);
    }

    /** Quotes: the filters, visibility and sort of their list. */
    public function quotes(Request $request): StreamedResponse
    {
        $filters = QuoteListQuery::filters($request->query());

        /** @var Builder<Model> $query */
        $query = QuoteListQuery::build($filters, $this->user($request));

        // A search term may be the name of a particulier.
        return $this->run($request, new QuoteExport, 'crm.quotes', $filters, $query, true);
    }

    public function checkQuotes(Request $request): JsonResponse
    {
        /** @var Builder<Model> $query */
        $query = QuoteListQuery::build(QuoteListQuery::filters($request->query()), $this->user($request));

        return $this->check($query);
    }

    public function checkOrganisations(Request $request): JsonResponse
    {
        return $this->checkResource($request, CrmResource::Organisations);
    }

    public function checkPeople(Request $request): JsonResponse
    {
        return $this->checkResource($request, CrmResource::People);
    }

    public function checkOpportunities(Request $request): JsonResponse
    {
        /** @var Builder<Model> $query */
        $query = OpportunityListQuery::build(OpportunityListQuery::filters($request->query()), $this->user($request));

        return $this->check($query);
    }

    private function checkResource(Request $request, CrmResource $resource): JsonResponse
    {
        $user = $this->user($request);

        return $this->check($resource->query($resource->filters($request->query(), $user), $user));
    }

    /**
     * Pre-flight of the download (the page calls it first): is the export
     * allowed, and why not. Writes nothing, journals nothing.
     *
     * @param  Builder<Model>  $query
     */
    private function check(Builder $query): JsonResponse
    {
        $size = $this->exporter->size($query);
        $allowed = $size['rows'] <= $size['max'];

        return response()->json([
            'rows' => $size['rows'],
            'max' => $size['max'],
            'allowed' => $allowed,
            'message' => $allowed ? null : XlsxExporter::tooManyRows($size['rows'], $size['max']),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function runResource(Request $request, CrmResource $resource): StreamedResponse
    {
        $user = $this->user($request);
        $filters = $resource->filters($request->query(), $user);

        // A people search term may be a name.
        return $this->run($request, $resource->export(), $resource->auditName(), $filters, $resource->query($filters, $user), $resource === CrmResource::People);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Builder<Model>  $query
     */
    private function run(Request $request, ExportResource $export, string $auditName, array $filters, Builder $query, bool $maskSearch): StreamedResponse
    {
        $user = $this->user($request);
        $columns = $this->columns($request, $export);

        $rows = $this->exporter->count($query);

        // Filters and size, never the content.
        $journaled = array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== false);
        if ($maskSearch && isset($journaled['q'])) {
            $journaled['q'] = Person::AUDIT_PERSONAL_MASK;
        }
        $this->audit->export($auditName, ['filters' => $journaled, 'columns' => $columns, 'rows' => $rows]);

        return $this->exporter->export($query, $export, $user, $columns, $export->fileName().'-'.now()->format('Ymd-Hi').'.xlsx', $rows);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }

    /**
     * @return list<string>
     */
    private function columns(Request $request, ExportResource $export): array
    {
        $asked = $request->query('columns');

        if ($asked === null || $asked === [] || $asked === '') {
            return $export->defaults();
        }

        $known = array_keys($export->columns());
        if (! is_array($asked) || array_filter($asked, fn ($c) => ! is_string($c) || ! in_array($c, $known, true)) !== []) {
            throw ValidationException::withMessages(['columns' => 'Colonne inconnue.']);
        }

        // The order the user chose, no duplicates.
        return array_values(array_unique($asked));
    }
}
