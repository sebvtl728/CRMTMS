<?php

namespace App\Modules\Core\CustomFields;

/** Types of a custom field. */
enum CustomFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Choice = 'choice';
    case File = 'file';
    /** Social security number (NIR): checked, sensitive (admissions.sensitive.view). */
    case Nir = 'nir';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texte court',
            self::Textarea => 'Texte long',
            self::Number => 'Nombre',
            self::Date => 'Date',
            self::Boolean => 'Oui / non',
            self::Choice => 'Liste de choix',
            self::File => 'Fichier joint',
            self::Nir => 'N° de sécurité sociale',
        };
    }

    /** Shown and written only with admissions.sensitive.view. */
    public function sensitive(): bool
    {
        return $this === self::Nir;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
