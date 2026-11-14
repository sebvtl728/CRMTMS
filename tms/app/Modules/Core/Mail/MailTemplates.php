<?php

namespace App\Modules\Core\Mail;

use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Subject and body of an e-mail for the current organisme: its own version
 * (mail_templates) or the default of the code. Rendering only replaces
 * {variable} by its value, as text: no template language, nothing typed is
 * ever executed. HTML e-mails escape the result when they print it.
 *
 * Cached per organisme (key prefixed by the tenant id), dropped on save.
 */
final class MailTemplates
{
    public const TTL_SECONDS = 3600;

    /** Longest subject: the template and, once filled, every subject the TMS proposes. */
    public const SUBJECT_MAX = 200;

    private const TOKEN = '/\{([a-z_]+)\}/';

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{subject: string, body: string, customised: bool}
     */
    public function get(string $key): array
    {
        $definition = self::definition($key);
        $default = ['subject' => $definition->subject, 'body' => $definition->body, 'customised' => false];
        if (! $definition->editable || $this->tenants->current() === null) {
            return $default;
        }

        /** @var array{subject: string, body: string, customised: bool} */
        return Cache::remember(self::cacheKey($this->tenants->id(), $key), self::TTL_SECONDS, function () use ($key, $default): array {
            $row = MailTemplate::query()->where('key', $key)->first();

            return $row === null ? $default : ['subject' => $row->subject, 'body' => $row->body, 'customised' => true];
        });
    }

    /**
     * The organisme's template with these values.
     *
     * @param  array<string, string>  $values
     * @return array{subject: string, body: string}
     */
    public function render(string $key, array $values): array
    {
        $template = $this->get($key);

        // One line, within the cap of the windows that send it (values may hold anything).
        $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', self::fill($template['subject'], $values)));

        return ['subject' => mb_substr($subject, 0, self::SUBJECT_MAX), 'body' => self::fill($template['body'], $values)];
    }

    /**
     * {name} replaced by its value; an unknown name is left as typed.
     *
     * @param  array<string, string>  $values
     */
    public static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(self::TOKEN, fn (array $m) => array_key_exists($m[1], $values) ? $values[$m[1]] : $m[0], $text);
    }

    /**
     * Sample values of the template's variables (preview, test e-mail).
     *
     * @return array<string, string>
     */
    public static function sample(string $key): array
    {
        return array_map(fn (array $v) => $v[1], self::definition($key)->variables);
    }

    /**
     * French messages per field: unknown variable, malformed brace, missing
     * required variable.
     *
     * @return array<string, string>
     */
    public static function errors(string $key, string $subject, string $body): array
    {
        $definition = self::definition($key);
        $errors = [];
        foreach (['subject' => $subject, 'body' => $body] as $field => $text) {
            preg_match_all(self::TOKEN, $text, $matches);
            $unknown = array_values(array_diff(array_unique($matches[1]), array_keys($definition->variables)));
            if ($unknown !== []) {
                $errors[$field] = 'Variable inconnue : {'.$unknown[0].'}.';

                continue;
            }
            $rest = (string) preg_replace(self::TOKEN, '', $text);
            if (str_contains($rest, '{') || str_contains($rest, '}')) {
                $errors[$field] = 'Accolade mal formée : écrivez une variable sous la forme {nom}.';
            }
        }
        if (! isset($errors['subject']) && preg_match('/[\x00-\x1F\x7F]/u', $subject) === 1) {
            $errors['subject'] = "L'objet doit tenir sur une ligne, sans caractère de contrôle.";
        }
        foreach ($definition->required as $variable) {
            if (! isset($errors['body']) && ! str_contains($body, '{'.$variable.'}')) {
                $errors['body'] = 'Gardez la variable {'.$variable.'} : '.($definition->variables[$variable][0] ?? 'elle est obligatoire').'.';
            }
        }

        return $errors;
    }

    /**
     * Creates or updates the organisme's row; a concurrent first save that
     * inserted it meanwhile is updated instead (no unique violation shown).
     */
    public function save(string $key, string $subject, string $body): MailTemplate
    {
        try {
            $row = DB::transaction(fn () => tap(MailTemplate::query()->firstOrNew(['key' => $key]), fn (MailTemplate $r) => $r->fill(['subject' => $subject, 'body' => $body])->save()));
        } catch (UniqueConstraintViolationException) {
            $row = DB::transaction(fn () => tap(MailTemplate::query()->where('key', $key)->lockForUpdate()->firstOrNew(['key' => $key]), fn (MailTemplate $r) => $r->fill(['subject' => $subject, 'body' => $body])->save()));
        }
        $this->forget($key);

        return $row;
    }

    public function reset(string $key): void
    {
        MailTemplate::query()->where('key', $key)->first()?->delete();
        $this->forget($key);
    }

    public function forget(string $key): void
    {
        Cache::forget(self::cacheKey($this->tenants->id(), $key));
    }

    public static function cacheKey(string $tenantId, string $key): string
    {
        return $tenantId.':mail-template:'.$key;
    }

    public static function definition(string $key): MailTemplateDefinition
    {
        return MailTemplateRegistry::find($key) ?? throw new InvalidArgumentException("Unknown mail template [{$key}].");
    }
}
