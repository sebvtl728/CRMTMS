<?php

namespace App\Modules\Catalogue\Rncp;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/** The RNCP fiche of a code, for the training form (catalogue.view). */
class RncpController extends Controller
{
    public function show(string $code, RncpDirectory $directory): JsonResponse
    {
        $fiche = $directory->find($code);
        if ($fiche === null) {
            return response()->json(['found' => false, 'loaded' => $directory->loaded()]);
        }

        return response()->json([
            'found' => true,
            'code' => $fiche['code'],
            'title' => $fiche['title'],
            'level' => $fiche['level'],
            'state' => RncpDirectory::state($fiche),
            'end_on' => $fiche['end_on'] === null ? null : CarbonImmutable::parse($fiche['end_on'])->format('d/m/Y'),
            'blocks' => count($fiche['blocks']),
            'suggestion' => RncpDirectory::suggestion($fiche),
        ]);
    }
}
