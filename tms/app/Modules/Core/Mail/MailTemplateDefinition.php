<?php

namespace App\Modules\Core\Mail;

/**
 * An e-mail of the TMS as the « Modèles d'e-mails » screen shows it: its key,
 * French label, default subject and body (plain text with {variables}), the
 * variables it offers (name => [explanation, sample value for the preview]),
 * those that must stay in the body, and whether it is editable (security
 * e-mails are « modèles système », read only).
 */
final readonly class MailTemplateDefinition
{
    /**
     * @param  array<string, array{0: string, 1: string}>  $variables
     * @param  list<string>  $required  variables the body must keep
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $subject,
        public string $body,
        public array $variables = [],
        public array $required = [],
        public bool $editable = true,
    ) {}
}
