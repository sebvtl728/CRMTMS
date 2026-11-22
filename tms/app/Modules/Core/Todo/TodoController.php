<?php

namespace App\Modules\Core\Todo;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** « À traiter »: what each module declares, within the user's rights and scopes. */
class TodoController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        assert($user instanceof User);
        abort_unless(TodoSources::any($user), 403);

        return Inertia::render('todo/index', ['rows' => TodoSources::for($user), 'groups' => TodoSources::GROUPS]);
    }
}
