<?php

namespace App\Modules\Crm\Search;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Support\FilterInput;
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

        return response()->json($search->query(FilterInput::text($request->query('q')), $user))
            ->header('Cache-Control', 'no-store, private');
    }
}
