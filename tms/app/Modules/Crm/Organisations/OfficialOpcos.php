<?php

namespace App\Modules\Crm\Organisations;

use Illuminate\Support\Str;

/**
 * The official attachment of collective agreements (IDCC) to the eleven
 * OPCO, from the « Table SIRET-OPCO » of France compétences (embedded in
 * official-opco-idcc.php). Used when no fiche lists the IDCC in its
 * covered agreements (covered_idcc, which always wins).
 */
final class OfficialOpcos
{
    public const SOURCE = 'Table SIRET-OPCO de France compétences (mise à jour d\'août 2026)';

    /**
     * Label, then the names a fiche of this OPCO may carry (compared after
     * lowercasing, removing accents and punctuation): `=` exact name,
     * otherwise contained in the name.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const OPCOS = [
        'afdas' => ['AFDAS', ['afdas']],
        'akto' => ['AKTO', ['akto']],
        'atlas' => ['ATLAS', ['=atlas', '=opco atlas']],
        'constructys' => ['Constructys', ['constructys']],
        'ocapiat' => ['OCAPIAT', ['ocapiat']],
        'opco_2i' => ['OPCO 2i', ['opco 2i', 'opco2i', 'interindustriel']],
        'cohesion_sociale' => ['OPCO Cohésion sociale', ['uniformation', 'opco cohesion sociale']],
        'opco_ep' => ['OPCO EP', ['opco ep', 'opcoep', 'entreprises de proximite']],
        'mobilites' => ['OPCO Mobilités', ['opco mobilites']],
        'sante' => ['OPCO Santé', ['opco sante']],
        'opcommerce' => ['Opcommerce', ['opcommerce']],
    ];

    /** @var array<string, list<string>>|null */
    private static ?array $table = null;

    /** @var array<string, string>|null */
    private static ?array $byIdcc = null;

    /** The OPCO key of an IDCC; null when the table does not know it. */
    public static function of(string $idcc): ?string
    {
        if (self::$byIdcc === null) {
            self::$byIdcc = [];
            foreach (self::table() as $key => $codes) {
                foreach ($codes as $code) {
                    self::$byIdcc[$code] = $key;
                }
            }
        }

        return self::$byIdcc[$idcc] ?? null;
    }

    public static function label(string $key): string
    {
        return self::OPCOS[$key][0];
    }

    /** Whether the name of a fiche is the name of this OPCO. */
    public static function names(string $key, string $name): bool
    {
        $normalised = trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($name))));
        foreach (self::OPCOS[$key][1] as $alias) {
            if (str_starts_with($alias, '=') ? $normalised === substr($alias, 1) : str_contains($normalised, $alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Words to look for in fiche names (SQL prefilter of names()).
     *
     * @return list<string>
     */
    public static function searchTerms(string $key): array
    {
        return array_map(fn (string $alias) => ltrim($alias, '='), self::OPCOS[$key][1]);
    }

    /**
     * The eleven OPCO by label, with their official IDCC.
     *
     * @return list<array{key: string, label: string, idcc: list<string>}>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::OPCOS as $key => [$label]) {
            $options[] = ['key' => $key, 'label' => $label, 'idcc' => self::table()[$key] ?? []];
        }
        usort($options, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return $options;
    }

    /** @return array<string, list<string>> */
    private static function table(): array
    {
        return self::$table ??= require __DIR__.'/official-opco-idcc.php';
    }
}
