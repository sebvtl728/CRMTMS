<?php

namespace App\Modules\Crm\Brochures;

use App\Modules\Catalogue\Brochures;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sendings as the pages show them: date, author, recipient, brochure titles
 * and status (« En attente », « Envoyée », « Échec »), never the address,
 * the message nor the mail server's text.
 */
final class BrochureSendingRows
{
    /**
     * @param  EloquentCollection<int, BrochureSending>  $sendings
     * @return list<array{id: string, at: string, author: string|null, recipient: string, titles: list<string>, versions: list<array{brochure_id: string, version_id: string, title: string, downloadable: bool}>, status: string, status_label: string}>
     */
    public static function of(EloquentCollection $sendings): array
    {
        $sendings->loadMissing(['recipient', 'author:id,name']);
        $links = DB::table('brochure_sending_versions')->whereIn('brochure_sending_id', $sendings->pluck('id')->all())
            ->orderBy('position')->get(['brochure_sending_id', 'brochure_version_id'])->groupBy('brochure_sending_id');
        $found = app(Brochures::class)->sent($links->flatten(1)->pluck('brochure_version_id')->unique()->values()->all());

        // Downloadable from the library (catalogue.view): a sent version stays so, even when its brochure is in the trash.
        $canDownload = Auth::user()?->can('catalogue.view') === true;

        return $sendings->map(function (BrochureSending $s) use ($links, $found, $canDownload): array {
            $versions = [];
            foreach ($links[$s->id] ?? [] as $link) {
                $brochure = $found[$link->brochure_version_id] ?? null;
                if ($brochure !== null) {
                    $versions[] = ['brochure_id' => $brochure['brochure_id'], 'version_id' => $link->brochure_version_id, 'title' => $brochure['title'], 'downloadable' => $canDownload];
                }
            }

            return [
                'id' => $s->id,
                'at' => $s->created_at?->toIso8601String() ?? '',
                'author' => $s->author?->name,
                'recipient' => $s->recipient?->fullName() ?? '',
                'titles' => array_column($versions, 'title'),
                'versions' => $versions,
                'status' => $s->email_status,
                'status_label' => BrochureSending::STATUS_LABELS[$s->email_status] ?? $s->email_status,
            ];
        })->values()->all();
    }

    /**
     * The sendings of an opportunity, latest first.
     *
     * @return list<array{id: string, at: string, author: string|null, recipient: string, titles: list<string>, versions: list<array{brochure_id: string, version_id: string, title: string, downloadable: bool}>, status: string, status_label: string}>
     */
    public static function forOpportunity(string $opportunityId): array
    {
        /** @var Builder<BrochureSending> $query */
        $query = BrochureSending::query()->where('opportunity_id', $opportunityId);

        return self::of($query->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get());
    }
}
