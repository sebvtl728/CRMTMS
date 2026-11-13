<?php

namespace App\Modules\Crm\Search;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON endpoint of the Ctrl+K palette.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $term = $request->query('q');

        return response()->json($search->query(is_string($term) ? mb_substr($term, 0, 100) : '', $user))
            ->header('Cache-Control', 'no-store, private');
    }
}
