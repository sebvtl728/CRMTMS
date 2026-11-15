<?php

namespace App\Modules\Crm\Organisations;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Sirene\CompanyLookupResult;
use App\Modules\Connectors\Sirene\SireneClient;
use App\Modules\Connectors\Sirene\SireneUnavailable;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Permissions;
use App\Rules\Siret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Prefill data for the entreprise form from a SIRET (Sirene connector). The
 * form fills only its empty fields; the user validates before saving.
 */
class SireneLookupController extends Controller
{
    public function __invoke(Request $request, string $siret, SireneClient $sirene): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && $user->can(Permissions::FICHES_UPDATE), 403);

        $validator = Validator::make(['siret' => $siret], ['siret' => ['required', new Siret]]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('siret'), 'errors' => $validator->errors()], 422);
        }

        try {
            $result = $sirene->lookup($siret);
        } catch (SireneUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() === 404 ? 404 : 503);
        }

        if ($result === null) {
            return response()->json(['message' => 'Aucune entreprise trouvée pour ce SIRET.'], 404);
        }

        return response()->json(['data' => $this->payload($result)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CompanyLookupResult $r): array
    {
        $legalForm = $r->legalFormLabel === null ? null : $this->matchLabel(ReferenceItem::options(ReferenceList::LegalFormOrg), $r->legalFormLabel);
        $size = $this->matchSize(ReferenceItem::options(ReferenceList::CompanySize), $r);

        return [
            'name' => $r->name,
            'siret' => $r->siret,
            'naf_code' => $r->nafCode,
            'address_line1' => $r->addressLine1,
            'postal_code' => $r->postalCode,
            'city' => $r->city,
            'legal_form_label' => $r->legalFormLabel,
            'legal_form_id' => $legalForm?->id,
            'employee_range_label' => $r->employeeRangeLabel,
            'company_size_id' => $size?->id,
            'company_size_label' => $size?->label,
        ];
    }

    /**
     * @param  Collection<int, ReferenceItem>  $items
     */
    private function matchLabel(Collection $items, string $label): ?ReferenceItem
    {
        $wanted = self::normalise($label);

        return $items->first(fn (ReferenceItem $i) => self::normalise($i->label) === $wanted);
    }

    /**
     * Same label, else the first size whose "10-49" / "250 et plus" / "0 salarié"
     * range contains the whole employee range of the establishment.
     *
     * @param  Collection<int, ReferenceItem>  $items
     */
    private function matchSize(Collection $items, CompanyLookupResult $r): ?ReferenceItem
    {
        if ($r->employeeRangeLabel === null || $r->employeeMin === null) {
            return null;
        }
        if (($same = $this->matchLabel($items, $r->employeeRangeLabel)) !== null) {
            return $same;
        }

        return $items->first(function (ReferenceItem $i) use ($r): bool {
            $range = self::parseRange($i->label);

            return $range !== null
                && $r->employeeMin >= $range[0]
                && ($range[1] === null || ($r->employeeMax !== null && $r->employeeMax <= $range[1]));
        });
    }

    /**
     * @return array{0: int, 1: ?int}|null
     */
    private static function parseRange(string $label): ?array
    {
        $label = self::normalise($label);

        return match (true) {
            preg_match('/^(\d+) (?:a )?(\d+)\b/', $label, $m) === 1 => [(int) $m[1], (int) $m[2]],
            preg_match('/^(\d+) (?:et plus|\+)/', $label, $m) === 1 => [(int) $m[1], null],
            preg_match('/^(\d+)(?: salaries?)?$/', $label, $m) === 1 => [(int) $m[1], (int) $m[1]],
            default => null,
        };
    }

    private static function normalise(string $label): string
    {
        return trim((string) preg_replace('/[^a-z0-9+]+/', ' ', Str::lower(Str::ascii($label))));
    }
}
