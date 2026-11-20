<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\References\ReferenceItem;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Excel template of an import: sheet « Données » (headers = the exact
 * labels of the target's fields, so that every column is proposed at once,
 * and one made-up example row) and sheet « Listes » (the active values of the
 * organisme's reference lists the file may use). The example row is marked in
 * its first cell (any cell is recognised: columns may have moved), leaves every
 * source identifier blank, and is ignored by the import if left in the file.
 */
final class ImportTemplate
{
    public const EXAMPLE_MARKER = 'Exemple - à supprimer';

    public const EXAMPLE_SKIPPED = "Ligne d'exemple du modèle ignorée.";

    /** Made-up example values (no real data), by field code. */
    private const EXAMPLES = [
        'siret' => '12345678900011', 'naf_code' => '8559A', 'email' => 'contact@exemple.test', 'email_secondary' => 'autre@exemple.test',
        'phone' => '01 23 45 67 89', 'mobile' => '06 12 34 56 78', 'website' => 'https://www.exemple.test', 'address_line1' => '1 rue de l\'Exemple',
        'postal_code' => '75001', 'city' => 'Paris', 'country' => 'France', 'birth_date' => '01/01/1990', 'first_name' => 'Prénom',
        'last_name' => 'Nom', 'is_individual' => 'Non', 'is_referent' => 'Oui', 'started_on' => '01/01/2026', 'date' => '01/01/2026',
        'work_email' => 'pro@exemple.test', 'work_phone' => '01 23 45 67 89', 'job_title' => 'Responsable formation',
        'duration_hours' => '14', 'duration_days' => '2', 'price_company' => '1200', 'price_individual' => '900', 'is_active' => 'Oui',
        'body' => 'Compte rendu de l\'échange',
    ];

    public static function filename(ImportTarget $target): string
    {
        return 'modele-import-'.$target->value.'.xlsx';
    }

    /**
     * True for the example row of a template left in the file: one of its
     * cells is the marker (the user may have moved the columns).
     *
     * @param  array<string, string|null>  $row
     */
    public static function isExample(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) === self::EXAMPLE_MARKER) {
                return true;
            }
        }

        return false;
    }

    public static function download(ImportTarget $target): StreamedResponse
    {
        $lists = self::lists($target);
        $example = self::example($target, $lists);

        return response()->streamDownload(function () use ($target, $lists, $example): void {
            $bold = (new Style)->setFontBold();
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->getCurrentSheet()->setName('Données');
            $writer->addRow(Row::fromValues(array_values($target->fields()), $bold));
            $writer->addRow(Row::fromValues($example));

            $writer->addNewSheetAndMakeItCurrent()->setName('Listes');
            if ($lists === []) {
                $writer->addRow(Row::fromValues(["Aucune liste de valeurs pour ce type d'import."]));
            } else {
                $writer->addRow(Row::fromValues(array_keys($lists), $bold));
                for ($i = 0, $max = max(array_map('count', $lists)); $i < $max; $i++) {
                    $writer->addRow(Row::fromValues(array_map(fn (array $values) => $values[$i] ?? '', array_values($lists))));
                }
            }
            $writer->close();
        }, self::filename($target), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Active labels of the organisme's lists, by field label.
     *
     * @return array<string, list<string>>
     */
    private static function lists(ImportTarget $target): array
    {
        $lists = [];
        foreach ($target->referenceFields() as $field => $list) {
            $lists[$target->fields()[$field]] = ReferenceItem::query()->where('list', $list->value)->where('is_active', true)
                ->orderBy('position')->pluck('label')->all();
        }

        return $lists;
    }

    /**
     * @param  array<string, list<string>>  $lists
     * @return list<string>
     */
    private static function example(ImportTarget $target, array $lists): array
    {
        $cells = [];
        $identifiers = $target->identifierFields();
        foreach ($target->fields() as $field => $label) {
            // No identifier: a template reimported as is must not call for a source software.
            $cells[] = in_array($field, $identifiers, true) ? '' : self::EXAMPLES[$field] ?? self::EXAMPLES[str_replace('organisation.', '', $field)] ?? ($lists[$label][0] ?? '');
        }
        $cells[0] = self::EXAMPLE_MARKER;

        return $cells;
    }
}
