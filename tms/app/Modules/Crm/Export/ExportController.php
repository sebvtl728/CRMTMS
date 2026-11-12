<?php

namespace App\Modules\Crm\Export;

use App\Http\Controllers\Controller;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Users\User;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Search\CrmResource;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel export of « Entreprises » / « Personnes » (crm.export): the same
 * filters, visibility and sort as the list, any number of columns among the
 * ones the resource offers. Audited with the filters and the row count.
 */
class ExportController extends Controller
{
    public function __construct(private readonly XlsxExporter $exporter, private readonly AuditRecorder $audit) {}

    public function organisations(Request $request): StreamedResponse
    {
        return $this->run($request, CrmResource::Organisations);
    }

    public function people(Request $request): StreamedResponse
    {
        return $this->run($request, CrmResource::People);
    }

    private function run(Request $request, CrmResource $resource): StreamedResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $export = $resource->export();
        $columns = $this->columns($request, $export);
        $filters = $resource->filters($request->query(), $user);
        $query = $resource->query($filters, $user);

        $rows = $this->exporter->count($query);

        // Filters and size, never the content. A people search term may be a name.
        $journaled = array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== false);
        if ($resource === CrmResource::People && isset($journaled['q'])) {
            $journaled['q'] = Person::AUDIT_PERSONAL_MASK;
        }
        $this->audit->export($resource->auditName(), ['filters' => $journaled, 'columns' => $columns, 'rows' => $rows]);

        return $this->exporter->export($query, $export, $columns, $export->fileName().'-'.now()->format('Ymd-Hi').'.xlsx', $rows);
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
