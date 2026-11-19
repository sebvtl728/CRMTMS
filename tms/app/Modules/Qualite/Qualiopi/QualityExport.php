<?php

namespace App\Modules\Qualite\Qualiopi;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Companies\Brand;
use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Qualite\Public\Measures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * The audit file of the Qualiopi referential, as a ZIP: sommaire.pdf (the 33
 * indicators: text, applicability, state, measures, proofs) and one folder
 * per indicator with its files. Built in a temporary file the caller sends
 * then deletes; every export is journalised.
 */
final class QualityExport
{
    public function __construct(private readonly PdfRenderer $renderer, private readonly AuditRecorder $audit) {}

    /** The path of the temporary ZIP. */
    public function build(User $by): string
    {
        $disk = Storage::disk(TenantStorage::DISK);
        $states = QualiopiFile::states();
        $proofs = QualityProof::query()->where('referential_version', QualiopiReferential::VERSION)
            ->orderBy('number')->orderBy('created_at')->orderBy('id')->get()->groupBy('number');
        $userNames = User::query()->whereIn('id', collect($states)->pluck('responsible_user_id')->filter()->unique()->values())->pluck('name', 'id');

        $files = [];
        $criteria = [];
        foreach (QualiopiReferential::indicators() as $indicator) {
            $n = $indicator['number'];
            $state = $states[$n] ?? null;
            $used = [];
            $rows = [];
            foreach ($proofs->get($n, collect()) as $proof) {
                /** @var QualityProof $proof */
                $entry = null;
                if ($proof->kind === 'file' && $proof->path !== null && QualityProofs::isOwnPath($proof->path) && $disk->exists($proof->path)) {
                    $entry = sprintf('indicateur-%02d/%s', $n, self::unique(self::safeName((string) $proof->original_name, $proof->path), $used));
                    $files[$entry] = $proof->path;
                }
                $rows[] = [
                    'kind' => $proof->kind, 'title' => $proof->title, 'url' => $proof->url, 'note' => $proof->note,
                    'entry' => $entry, 'missing' => $proof->kind === 'file' && $entry === null,
                    'date' => $proof->created_at?->setTimezone('Europe/Paris')->format('d/m/Y'),
                ];
            }
            $row = QualiopiController::row($indicator, $state);
            $criteria[$indicator['criterion']][] = [
                ...$row,
                'status_label' => QualityIndicator::STATUSES[$row['status']] ?? $row['status'],
                'responsible' => $row['responsible_user_id'] === null ? null : ($userNames[$row['responsible_user_id']] ?? null),
                'review_on' => $row['review_on'] === null ? null : CarbonImmutable::parse($row['review_on'])->format('d/m/Y'),
                'measures' => Measures::for($n),
                'proofs' => $rows,
            ];
        }

        $progress = QualiopiFile::progress();
        $pdf = $this->renderer->fromHtml(view('documents.qualiopi-summary', [
            'organisme' => Brand::company()->name ?? '',
            'date' => CarbonImmutable::now('Europe/Paris')->format('d/m/Y'),
            'decree' => QualiopiReferential::DECREE,
            'notice' => QualiopiReferential::VERIFY_NOTICE,
            'progress' => $progress,
            'criteria' => $criteria,
            'criteriaTitles' => QualiopiReferential::CRITERIA,
        ])->render());

        $path = tempnam(sys_get_temp_dir(), 'qualiopi-');
        if ($path === false) {
            throw new RuntimeException('Temporary file unavailable.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('ZIP unavailable.');
            }
            $zip->addFromString('sommaire.pdf', $pdf);
            foreach ($files as $entry => $stored) {
                $zip->addFile($disk->path($stored), $entry);
            }
            $zip->close();
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }

        $this->audit->record('qualite.qualiopi.exported', null, [], ['files' => count($files), 'ready' => $progress['ready'], 'applicable' => $progress['applicable']], $by);

        return $path;
    }

    /** A file name safe in a ZIP: no folder, no control or reserved character. */
    public static function safeName(string $original, string $stored): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', basename(str_replace('\\', '/', $original))), ' ._');
        $extension = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
        if ($name === '') {
            return 'preuve.'.$extension;
        }

        return mb_substr($name, 0, 150);
    }

    /** @param array<string, true> $used */
    private static function unique(string $name, array &$used): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name;
        for ($i = 2; isset($used[mb_strtolower($candidate)]); $i++) {
            $candidate = $base.' ('.$i.')'.($extension === '' ? '' : '.'.$extension);
        }
        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }
}
