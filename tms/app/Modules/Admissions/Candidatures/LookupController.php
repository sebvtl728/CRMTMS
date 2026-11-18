<?php

namespace App\Modules\Admissions\Candidatures;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Public\DuplicateTrainee;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Searches in the CRM for the candidature forms (minimal identity, whole
 * organisme, never cached), and the creation of the person of a new
 * candidate (a particulier; a similar person is reported, 409, unless
 * confirmed).
 */
class LookupController extends Controller
{
    public function organisations(Request $request, Fiches $fiches): JsonResponse
    {
        $q = $request->query('q');

        return response()->json(['entreprises' => is_string($q) && mb_strlen(trim($q)) >= 2 ? $fiches->searchOrganisations(mb_substr($q, 0, 100)) : []])
            ->header('Cache-Control', 'no-store, private');
    }

    public function people(Request $request, Trainees $trainees): JsonResponse
    {
        $q = $request->query('q');
        $organisation = $request->query('organisation');

        return response()->json(['personnes' => is_string($q) && mb_strlen(trim($q)) >= 2
            ? $trainees->search(mb_substr($q, 0, 100), is_string($organisation) ? $organisation : null)
            : []])->header('Cache-Control', 'no-store, private');
    }

    public function storePerson(Request $request, Trainees $trainees): JsonResponse
    {
        try {
            $id = $trainees->create([
                ...$request->only(['civility', 'first_name', 'last_name', 'email', 'phone', 'mobile', 'postal_code', 'city', 'birth_date']),
                'is_individual' => true,
                'confirm_duplicate' => $request->boolean('confirm_duplicate'),
            ]);
        } catch (DuplicateTrainee $e) {
            return response()->json(['message' => DuplicateTrainee::MESSAGE, 'duplicates' => $e->candidates], 409);
        }

        return response()->json(['id' => $id], 201);
    }
}
