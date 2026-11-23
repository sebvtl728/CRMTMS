<?php

namespace App\Modules\Catalogue\Rncp;

use App\Modules\Catalogue\BpfObjective;
use App\Modules\Catalogue\Competences;
use App\Modules\Catalogue\Nsf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads the RNCP certifications (rncp_certifications): a fiche, its state
 * for the training (active, ending within ENDING_MONTHS, inactive) and what
 * it suggests for the training sheet (BPF objective, NSF group, a competence
 * grid with one activity type per block).
 */
final class RncpDirectory
{
    public const ENDING_MONTHS = 6;

    /**
     * @return array{code: string, title: string, level: ?int, active: bool, end_on: ?string, nsf_codes: list<string>, blocks: list<array{code: string, label: string}>}|null
     */
    public function find(string $code): ?array
    {
        $code = mb_strtoupper(trim($code));
        if (preg_match('/^RNCP\d{1,6}$/', $code) !== 1) {
            return null;
        }
        $row = DB::table('rncp_certifications')->where('code', $code)->first();
        if ($row === null) {
            return null;
        }

        return [
            'code' => (string) $row->code,
            'title' => (string) $row->title,
            'level' => $row->level === null ? null : (int) $row->level,
            'active' => (bool) $row->active,
            'end_on' => $row->end_on === null ? null : substr((string) $row->end_on, 0, 10),
            'nsf_codes' => array_values(json_decode((string) $row->nsf_codes, true) ?: []),
            'blocks' => array_values(json_decode((string) $row->blocks, true) ?: []),
        ];
    }

    /** Whether the directory was ever loaded (an empty table says nothing about a code). */
    public function loaded(): bool
    {
        return DB::table('rncp_certifications')->exists();
    }

    /**
     * @param  array{active: bool, end_on: ?string}  $fiche
     * @return 'active'|'ending'|'inactive'
     */
    public static function state(array $fiche, ?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::now((string) config('app.timezone'))->startOfDay();
        if (! $fiche['active'] || ($fiche['end_on'] !== null && $fiche['end_on'] < $today->toDateString())) {
            return 'inactive';
        }

        return $fiche['end_on'] !== null && $fiche['end_on'] <= $today->addMonths(self::ENDING_MONTHS)->toDateString() ? 'ending' : 'active';
    }

    /**
     * @param  array{level: ?int, nsf_codes: list<string>, blocks: list<array{code: string, label: string}>}  $fiche
     * @return array{bpf_objective: ?string, nsf_code: ?string, competences_text: string}
     */
    public static function suggestion(array $fiche): array
    {
        $objective = match ($fiche['level']) {
            6, 7, 8 => BpfObjective::Rncp68,
            5 => BpfObjective::Rncp5,
            4 => BpfObjective::Rncp4,
            3 => BpfObjective::Rncp3,
            2 => BpfObjective::Rncp2,
            default => null,
        };
        $nsf = null;
        foreach ($fiche['nsf_codes'] as $code) {
            $group = substr($code, 0, 3);
            if (Nsf::label($group) !== null) {
                $nsf = $group;
                break;
            }
        }
        $grid = array_map(fn (array $block) => ['title' => mb_substr($block['label'], 0, Competences::MAX_LINE - 3), 'competences' => []], $fiche['blocks']);

        return ['bpf_objective' => $objective?->value, 'nsf_code' => $nsf, 'competences_text' => Competences::toText($grid === [] ? null : $grid)];
    }

    /**
     * Codes among $codes needing attention: unknown, inactive or ending soon
     * (none while the directory was never loaded).
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function alerts(array $codes): array
    {
        $codes = array_values(array_unique(array_map(fn ($c) => mb_strtoupper(trim($c)), array_filter($codes))));
        if ($codes === [] || ! $this->loaded()) {
            return [];
        }
        $known = DB::table('rncp_certifications')->whereIn('code', $codes)->get(['code', 'active', 'end_on'])
            ->mapWithKeys(fn ($r) => [(string) $r->code => ['active' => (bool) $r->active, 'end_on' => $r->end_on === null ? null : substr((string) $r->end_on, 0, 10)]]);

        return array_values(array_filter($codes, fn (string $c) => ! isset($known[$c]) || self::state($known[$c]) !== 'active'));
    }
}
