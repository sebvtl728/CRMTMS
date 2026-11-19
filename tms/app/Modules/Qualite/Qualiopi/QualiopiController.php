<?php

namespace App\Modules\Qualite\Qualiopi;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Qualite\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Qualité › Qualiopi: the 33 indicators by criterion, and one indicator. */
class QualiopiController extends Controller
{
    public function index(Request $request): Response
    {
        $states = QualiopiFile::states();
        $criteria = [];
        foreach (QualiopiReferential::CRITERIA as $n => $title) {
            $criteria[] = [
                'number' => $n,
                'title' => $title,
                'indicators' => array_values(array_map(fn (array $i) => self::row($i, $states[$i['number']] ?? null),
                    array_filter(QualiopiReferential::indicators(), fn (array $i) => $i['criterion'] === $n))),
            ];
        }

        return Inertia::render('qualite/qualiopi/index', [
            'criteria' => $criteria,
            'progress' => QualiopiFile::progress(),
            'decree' => QualiopiReferential::DECREE,
            'verify_notice' => QualiopiReferential::VERIFY_NOTICE,
            'statuses' => QualityIndicator::STATUSES,
            'can' => ['manage' => self::user($request)->can(Permissions::MANAGE)],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, int $number, QualiopiFile $file): RedirectResponse
    {
        abort_if(QualiopiReferential::indicator($number) === null, 404);
        $file->update($number, $request->only(['applicable', 'not_applicable_reason', 'status', 'responsible_user_id', 'review_on', 'notes']), self::user($request));

        return back()->with('status', "Indicateur {$number} enregistré.");
    }

    /**
     * @param  array<string, mixed>  $indicator
     * @return array<string, mixed>
     */
    public static function row(array $indicator, ?QualityIndicator $state): array
    {
        return [
            ...$indicator,
            'applicable' => $state === null || $state->applicable,
            'not_applicable_reason' => $state?->not_applicable_reason,
            'status' => $state->status ?? 'todo',
            'responsible_user_id' => $state?->responsible_user_id,
            'review_on' => $state?->review_on?->toDateString(),
            'notes' => $state?->notes,
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
