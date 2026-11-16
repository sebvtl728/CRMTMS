<?php

namespace App\Modules\Sessions\Dossiers;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A second dossier asked for an accepted quote (double click, second tab):
 * 409. The assistant (JSON) receives the existing dossier's number and, when
 * the user may open it, its link; an HTML request gets the error page.
 */
final class DossierExists extends UserFacingHttpException
{
    public const MESSAGE = 'Un dossier existe déjà pour ce devis.';

    public function __construct(public readonly Dossier $dossier)
    {
        parent::__construct(409, self::MESSAGE);
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json(['message' => self::MESSAGE, 'dossier' => self::link($this->dossier, $request->user())], 409);
    }

    /**
     * Number of the dossier, and its URL when $user sees it.
     *
     * @return array{number: string, url: ?string}
     */
    public static function link(Dossier $dossier, mixed $user): array
    {
        $visible = $user instanceof User && SessionsVisibility::allows($user, Permissions::DOSSIERS_VIEW, $dossier)
            && (! $dossier->trashed() || SessionsVisibility::allows($user, Permissions::DOSSIERS_DELETE, $dossier));

        return ['number' => $dossier->number, 'url' => $visible ? route('dossiers.show', $dossier) : null];
    }
}
