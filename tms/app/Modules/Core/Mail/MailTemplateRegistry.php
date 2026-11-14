<?php

namespace App\Modules\Core\Mail;

/**
 * The e-mail templates the modules declare (Core: invitation and system
 * e-mails; Crm: quotes, daily digest), so that Core never depends on a
 * module. Lives in the application container: a fresh application (each
 * test) starts with only what the providers register.
 */
final class MailTemplateRegistry
{
    /** @var array<string, MailTemplateDefinition> */
    private array $definitions = [];

    public static function register(MailTemplateDefinition $definition): void
    {
        self::registry()->definitions[$definition->key] = $definition;
    }

    public static function find(string $key): ?MailTemplateDefinition
    {
        return self::registry()->definitions[$key] ?? null;
    }

    /**
     * @return list<MailTemplateDefinition> editable ones first, then by label
     */
    public static function all(): array
    {
        $all = array_values(self::registry()->definitions);
        usort($all, fn (MailTemplateDefinition $a, MailTemplateDefinition $b) => [$b->editable, $a->label] <=> [$a->editable, $b->label]);

        return $all;
    }

    private static function registry(): self
    {
        $app = app();
        if (! $app->bound(self::class)) {
            $app->instance(self::class, new self);
        }

        return $app->make(self::class);
    }
}
