<?php

namespace App\Modules\Crm\Gdpr;

use App\Modules\Crm\People\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What identifies a person in raw data she is not linked to by id (an
 * uploaded file, the raw values of an import row that failed): her e-mail
 * addresses, her phone numbers (last 9 digits, whatever the format) and her
 * full name (last AND first name in the same row). Compared without case,
 * spaces or accents. A last name alone is never enough (homonyms).
 */
final class PersonTraces
{
    /**
     * @param  list<string>  $emails  lower-cased
     * @param  list<string>  $digits  last 9 digits of each phone
     * @param  array{0: string, 1: string}|null  $name  last and first name, lower-cased, without accents
     */
    private function __construct(public readonly array $emails, public readonly array $digits, public readonly ?array $name) {}

    public static function of(Person $person): self
    {
        $links = DB::table('affiliations')->where('person_id', $person->id)->get(['work_email', 'work_phone']);

        $emails = collect([$person->email, $person->email_secondary, ...$links->pluck('work_email')])
            ->filter(fn ($e) => is_string($e) && trim($e) !== '')->map(fn (string $e) => mb_strtolower(trim($e)))->unique()->values()->all();
        $digits = collect([$person->phone, $person->mobile, ...$links->pluck('work_phone')])
            ->map(fn ($p) => is_string($p) ? self::lastDigits($p) : null)->filter()->unique()->values()->all();
        $first = self::plain($person->first_name);
        $last = self::plain($person->last_name);

        return new self($emails, $digits, $first !== '' && $last !== '' ? [$last, $first] : null);
    }

    public function isEmpty(): bool
    {
        return $this->emails === [] && $this->digits === [] && $this->name === null;
    }

    /**
     * @param  array<array-key, mixed>  $cells
     */
    public function matches(array $cells): bool
    {
        $plain = [];
        foreach ($cells as $cell) {
            if (! is_scalar($cell)) {
                continue;
            }
            $text = self::plain((string) $cell);
            if ($text === '') {
                continue;
            }
            if (in_array($text, $this->emails, true)) {
                return true;
            }
            $digits = self::lastDigits($text);
            if ($digits !== null && in_array($digits, $this->digits, true)) {
                return true;
            }
            $plain[$text] = true;
        }

        return $this->name !== null && isset($plain[$this->name[0]], $plain[$this->name[1]]);
    }

    /**
     * SQL condition on a jsonb object column of raw values (import_rows.source).
     *
     * @return array{0: string, 1: list<string>}
     */
    public function sql(string $column): array
    {
        $value = 'public.immutable_unaccent(lower(btrim(e.value)))';
        $digits = "regexp_replace(e.value, '\\D', '', 'g')";
        $parts = [];
        $bindings = [];

        if ($this->emails !== []) {
            $parts[] = "exists (select 1 from jsonb_each_text({$column}) e where lower(btrim(e.value)) in (select jsonb_array_elements_text(?::jsonb)))";
            $bindings[] = (string) json_encode($this->emails);
        }
        if ($this->digits !== []) {
            $parts[] = "exists (select 1 from jsonb_each_text({$column}) e where length({$digits}) between 9 and 15 and right({$digits}, 9) in (select jsonb_array_elements_text(?::jsonb)))";
            $bindings[] = (string) json_encode($this->digits);
        }
        if ($this->name !== null) {
            $parts[] = "(exists (select 1 from jsonb_each_text({$column}) e where {$value} = public.immutable_unaccent(?))"
                ." and exists (select 1 from jsonb_each_text({$column}) e where {$value} = public.immutable_unaccent(?)))";
            $bindings[] = $this->name[0];
            $bindings[] = $this->name[1];
        }

        return $parts === [] ? ['false', []] : ['('.implode(' or ', $parts).')', $bindings];
    }

    private static function plain(?string $text): string
    {
        return $text === null ? '' : mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', Str::ascii($text))));
    }

    /** Last 9 digits of a value that looks like a phone number (9 to 15 digits). */
    private static function lastDigits(string $text): ?string
    {
        if (preg_match('/[a-z@]/i', $text) === 1) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $text) ?? '';
        $length = strlen($digits);

        return $length >= 9 && $length <= 15 ? substr($digits, -9) : null;
    }
}
