<?php

use App\Modules\Crm\Import\EncodingDetector;
use App\Modules\Crm\Import\ImportFileException;
use App\Modules\Crm\Import\Readers\CsvReader;
use App\Modules\Crm\Import\Readers\Readers;
use App\Modules\Crm\Import\Readers\XlsxReader;

function fixture(string $name): string
{
    return base_path('tests/Fixtures/imports/'.$name);
}

/**
 * @param  iterable<int, array<string, string>>  $rows
 * @return array<int, array<string, string>>
 */
function allRows(iterable $rows): array
{
    $out = [];
    foreach ($rows as $line => $row) {
        $out[$line] = $row;
    }

    return $out;
}

function tempCsv(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($path, $content);

    return $path;
}

it('reads a utf-8 semicolon file with quoted delimiters, quotes and newlines', function () {
    $reader = CsvReader::open(fixture('utf8_semicolon.csv'));

    expect($reader->delimiter())->toBe(';')
        ->and($reader->encoding())->toBe('UTF-8')
        ->and($reader->headers())->toBe(['Raison sociale', 'SIRET', 'CP', 'Ville', 'Tél', 'Mail', 'Origine'])
        ->and($reader->warnings())->toBe([]);

    $rows = allRows($reader->rows());
    expect(array_keys($rows))->toBe([2, 3, 4])
        ->and($rows[2])->toBe(['Raison sociale' => 'Hôtel du Lac', 'SIRET' => '73282932000074', 'CP' => '74000', 'Ville' => 'Annecy', 'Tél' => '04 50 00 00 00', 'Mail' => 'contact@hotel-lac.fr', 'Origine' => 'Salon'])
        ->and($rows[3]['Raison sociale'])->toBe('Boulangerie "Chez Zoé"; artisan')
        ->and($rows[3]['Tél'])->toBe('')
        ->and($rows[4]['Raison sociale'])->toBe("Café des Arts\n(siège)")
        ->and($rows[4]['Origine'])->toBe('Site web');
});

it('reads a windows-1252 excel export, ignoring empty lines and duplicate columns', function () {
    $reader = CsvReader::open(fixture('windows1252_excel.csv'));

    expect($reader->encoding())->toBe('Windows-1252')
        ->and($reader->delimiter())->toBe(';')
        ->and($reader->headers())->toBe(['Raison sociale', 'SIRET', 'Code postal', 'Ville', 'Téléphone', 'E-mail'])
        ->and($reader->warnings())->toBe(['La colonne « Raison sociale » apparaît plusieurs fois : seule la première est utilisée.']);

    $rows = allRows($reader->rows());
    expect(array_keys($rows))->toBe([2, 3, 4, 6])
        ->and($rows[2]['Raison sociale'])->toBe('Hôtel Bellevue')
        ->and($rows[3]['Raison sociale'])->toBe('Crêperie Ty Breizh')
        ->and($rows[3]['SIRET'])->toBe('7,32829E+13')
        ->and($rows[4]['Raison sociale'])->toBe('Brasserie Œnologie & Cie')
        ->and($rows[6]['Raison sociale'])->toBe('Pâtisserie Müller');
});

it('reads a comma file with a byte order mark', function () {
    $reader = CsvReader::open(fixture('comma_bom.csv'));

    expect($reader->delimiter())->toBe(',')
        ->and($reader->encoding())->toBe('UTF-8')
        ->and($reader->headers())->toBe(['Nom', 'Prénom', 'E-mail', 'Portable', 'Ville']);

    $rows = allRows($reader->rows());
    expect($rows)->toHaveCount(2)
        ->and($rows[2]['Ville'])->toBe('Saint-Étienne, Loire')
        ->and($rows[2]['Prénom'])->toBe('Hélène')
        ->and($rows[3]['Nom'])->toBe('Dupont, dit "Jo"');
});

it('reads a tab separated file keeping leading zeros', function () {
    $reader = Readers::open(fixture('tab.tsv'), 'export.tsv');

    expect($reader)->toBeInstanceOf(CsvReader::class)
        ->and($reader->delimiter())->toBe("\t")
        ->and($reader->headers())->toBe(['Raison sociale', 'SIRET', 'Ville', 'Secteur']);

    $rows = allRows($reader->rows());
    expect($rows[2]['SIRET'])->toBe('09123456700021')
        ->and($rows[2]['Raison sociale'])->toBe('Hôtel de la Plage');
});

it('reads the first sheet of an xlsx as text', function () {
    $reader = Readers::open(fixture('contacts.xlsx'), 'Contacts.XLSX');

    expect($reader)->toBeInstanceOf(XlsxReader::class)
        ->and($reader->headers())->toBe(['Nom', 'Prénom', 'E-mail', 'Fonction', 'Entreprise', 'SIRET', 'Ville', 'Depuis']);

    $rows = allRows($reader->rows());
    expect(array_keys($rows))->toBe([2, 3, 4, 6, 7])
        // A SIRET typed as a number comes back as its digits.
        ->and($rows[2]['SIRET'])->toBe('73282932000074')
        ->and($rows[2]['Prénom'])->toBe('Élodie')
        ->and($rows[2]['Depuis'])->toBe('15/03/2024')
        ->and($rows[3]['Depuis'])->toBe('')
        // Leading zero lost by Excel: the digits are kept, the SIRET field restores it.
        ->and($rows[4]['SIRET'])->toBe('1234567800010')
        // Digits lost through a scientific notation: kept as read, refused later.
        ->and($rows[6]['SIRET'])->toBe('73282900000000')
        // A decimal number keeps its decimal part (never silently rounded).
        ->and($rows[7]['SIRET'])->toBe('1234.5');
});

it('can be read twice (simulation then run)', function () {
    $reader = CsvReader::open(fixture('utf8_semicolon.csv'));

    expect(allRows($reader->rows()))->toHaveCount(3)
        ->and(allRows($reader->rows()))->toHaveCount(3);
});

it('accepts a forced delimiter and encoding', function () {
    $path = tempCsv(mb_convert_encoding("Nom|Ville\nRené|Nîmes\n", 'Windows-1252', 'UTF-8'));

    $reader = CsvReader::open($path, '|', 'Windows-1252');

    expect($reader->headers())->toBe(['Nom', 'Ville'])
        ->and(allRows($reader->rows()))->toBe([2 => ['Nom' => 'René', 'Ville' => 'Nîmes']]);
});

it('pads short rows, ignores extra cells and names empty headers', function () {
    $path = tempCsv("Nom;;Ville\nA;x\nB;y;Lyon;en trop\n");

    $reader = CsvReader::open($path);

    expect($reader->headers())->toBe(['Nom', 'Colonne 2', 'Ville'])
        ->and(allRows($reader->rows()))->toBe([
            2 => ['Nom' => 'A', 'Colonne 2' => 'x', 'Ville' => ''],
            3 => ['Nom' => 'B', 'Colonne 2' => 'y', 'Ville' => 'Lyon'],
        ]);
});

it('detects the delimiter by its consistent count, not by a stray comma', function () {
    $path = tempCsv("Nom;SIRET;Ville\nA;7,32829E+13;Lyon\nB;1,5;Paris, 11e\n");

    expect(CsvReader::open($path)->delimiter())->toBe(';');
});

it('refuses unsupported or empty files with a french message', function (string $name, string $content, string $message) {
    $path = tempCsv($content);

    expect(fn () => Readers::open($path, $name))->toThrow(ImportFileException::class, $message);
})->with([
    'old excel' => ['clients.xls', 'x', 'Format .xls non pris en charge'],
    'other' => ['clients.pdf', 'x', 'Format de fichier non pris en charge'],
    'empty' => ['vide.csv', "\n\n", 'Le fichier est vide'],
    'fake xlsx' => ['faux.xlsx', 'Nom;Ville', 'Le fichier .xlsx est illisible'],
]);

it('detects the encoding of a sample', function (string $sample, string $expected) {
    expect(EncodingDetector::detect($sample))->toBe($expected);
})->with([
    'ascii' => ['Nom;Ville', 'UTF-8'],
    'utf-8' => ['Hôtel;Crêperie', 'UTF-8'],
    'utf-8 bom' => ["\xEF\xBB\xBFNom", 'UTF-8'],
    'windows-1252' => ["H\xF4tel;Cr\xEApe", 'Windows-1252'],
    'windows-1252 oe and euro' => ["\x8Cuvre \x80", 'Windows-1252'],
    // A sample cut in the middle of a character is still UTF-8.
    'cut utf-8' => [substr('Hôtel é', 0, -1), 'UTF-8'],
]);

it('detects repeated columns whatever their case', function () {
    $reader = CsvReader::open(tempCsv("Ville;VILLE;ville \nLyon;Paris;Pau\n"));

    expect($reader->headers())->toBe(['Ville'])
        ->and($reader->warnings())->toBe(['La colonne « VILLE » apparaît plusieurs fois : seule la première est utilisée.'])
        ->and(allRows($reader->rows()))->toBe([2 => ['Ville' => 'Lyon']]);
});
