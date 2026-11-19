<?php

namespace App\Modules\Qualite\Public;

use Throwable;

/**
 * Registry of the automatic measures of the Qualiopi indicators: each module
 * declares its own (it reads its own tables), the Qualité module never reads
 * theirs. A measure answers array{label, value, detail?, tone?} with
 * tone ok|warn|neutral; a failing one shows as « Mesure indisponible ».
 */
final class Measures
{
    public const TONES = ['ok', 'warn', 'neutral'];

    /** @var array<string, array{indicators: list<int>, measure: callable(): array<string, mixed>}> */
    private static array $measures = [];

    /**
     * @param  callable(): array<string, mixed>  $measure
     */
    public static function register(int $indicator, string $key, callable $measure): void
    {
        $indicators = self::$measures[$key]['indicators'] ?? [];
        if (! in_array($indicator, $indicators, true)) {
            $indicators[] = $indicator;
        }
        self::$measures[$key] = ['indicators' => $indicators, 'measure' => $measure];
    }

    public static function forget(string $key): void
    {
        unset(self::$measures[$key]);
    }

    /**
     * The measures of an indicator, in registration order.
     *
     * @return list<array{key: string, label: string, value: string, detail: ?string, tone: string}>
     */
    public static function for(int $indicator): array
    {
        $out = [];
        foreach (self::$measures as $key => $entry) {
            if (! in_array($indicator, $entry['indicators'], true)) {
                continue;
            }
            try {
                $m = ($entry['measure'])();
                $tone = (string) ($m['tone'] ?? 'neutral');
                $out[] = [
                    'key' => $key,
                    'label' => (string) $m['label'],
                    'value' => (string) $m['value'],
                    'detail' => isset($m['detail']) ? (string) $m['detail'] : null,
                    'tone' => in_array($tone, self::TONES, true) ? $tone : 'neutral',
                ];
            } catch (Throwable $e) {
                report($e);
                $out[] = ['key' => $key, 'label' => 'Mesure automatique', 'value' => 'Mesure indisponible', 'detail' => null, 'tone' => 'neutral'];
            }
        }

        return $out;
    }

    /**
     * « a / b », warn while a < b.
     *
     * @return array{value: string, tone: string}
     */
    public static function ratio(int $done, int $total): array
    {
        return ['value' => $done.' / '.$total, 'tone' => $total === 0 ? 'neutral' : ($done < $total ? 'warn' : 'ok')];
    }
}
