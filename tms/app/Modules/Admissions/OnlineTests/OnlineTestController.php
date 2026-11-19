<?php

namespace App\Modules\Admissions\OnlineTests;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Admissions › Tests en ligne (catalogue.manage, as the library). */
class OnlineTestController extends Controller
{
    public function __construct(private readonly OnlineTests $tests) {}

    public function index(Request $request): Response
    {
        $tests = OnlineTest::query()->orderBy('title')->orderBy('id')->get();
        $versions = OnlineTestVersion::query()->whereIn('id', $tests->pluck('current_version_id')->filter())->get()->keyBy('id');

        return Inertia::render('admissions/online-tests/index', [
            'tests' => $tests->map(fn (OnlineTest $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'published_version' => $versions->get($t->current_version_id)?->number,
                'total_points' => $versions->get($t->current_version_id)?->total_points,
                'time_limit_minutes' => $t->time_limit_minutes,
                'updated_at' => $t->updated_at?->toIso8601String(),
            ])->values()->all(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $test = $this->tests->create($request->only(['title']), self::user($request));

        return redirect("/admissions/online-tests/{$test->id}/edit")->with('status', 'Test créé : ajoutez ses sections et ses questions.');
    }

    public function edit(Request $request, string $test): Response
    {
        $model = self::find($test);
        $version = $model->current_version_id !== null ? OnlineTestVersion::query()->find($model->current_version_id) : null;

        return Inertia::render('admissions/online-tests/edit', [
            'test' => [
                'id' => $model->id,
                'title' => $model->title,
                'time_limit_minutes' => $model->time_limit_minutes,
                'das_ids' => DB::table('online_test_das')->where('online_test_id', $model->id)->pluck('das_id')->map(fn ($id) => (string) $id)->all(),
                'training_ids' => DB::table('online_test_trainings')->where('online_test_id', $model->id)->pluck('training_id')->map(fn ($id) => (string) $id)->all(),
                'content' => $model->draft_content,
                'published_version' => $version?->number,
                'published_at' => $version?->created_at?->toIso8601String(),
            ],
            'options' => [
                'das' => ReferenceItem::options(ReferenceList::Das)->map(fn (ReferenceItem $i) => ['value' => $i->id, 'label' => $i->label])->values()->all(),
                'trainings' => array_map(fn ($t) => ['value' => $t->id, 'label' => $t->title], app(Trainings::class)->options()),
            ],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, string $test): JsonResponse
    {
        $this->tests->update(self::find($test), $request->only(['title', 'time_limit_minutes', 'das_ids', 'training_ids', 'content']), self::user($request));

        return response()->json(['status' => 'Brouillon enregistré.']);
    }

    public function publish(Request $request, string $test): RedirectResponse
    {
        $version = $this->tests->publish(self::find($test), self::user($request));

        return back()->with('status', "Test publié (version {$version->number}).");
    }

    public function destroy(string $test): RedirectResponse
    {
        $this->tests->trash(self::find($test));

        return redirect('/admissions/online-tests')->with('status', 'Test mis à la corbeille.');
    }

    private static function find(string $id): OnlineTest
    {
        abort_unless(Str::isUuid($id), 404);

        return OnlineTest::query()->findOrFail($id);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
