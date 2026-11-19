<?php

namespace App\Modules\Admissions\OnlineTests;

use App\Modules\Catalogue\Assistant\PdfText;
use App\Modules\Connectors\Ai\Ai;
use App\Modules\Connectors\Ai\AiInvalidResponse;
use App\Modules\Connectors\Ai\AiUnavailable;
use App\Modules\Core\Users\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * « Créer depuis un PDF »: the text of a paper positioning test
 * (PdfText) is sent to the organisme's AI, which proposes sections and
 * questions in the content model. Unknown types are dropped and the right
 * answers are never kept (they are not in the paper test): the draft is
 * reviewed and completed before any publication.
 */
final class OnlineTestFromPdf
{
    public const PURPOSE = 'positioning.online_test_from_pdf';

    public const NOT_CONFIGURED = 'Configurez l\'intelligence artificielle dans Administration → Connecteurs.';

    public function __construct(private readonly Ai $ai, private readonly PdfText $pdf, private readonly OnlineTests $tests) {}

    /**
     * Reads the PDF now (refused at once when it is not one or has no
     * text), creates the test « Import de … » and lets the AI draft it in
     * the background (ImportOnlineTestFromPdf): a local AI may take minutes.
     */
    public function start(UploadedFile $file, User $by): OnlineTest
    {
        if (! $this->ai->configured()) {
            throw ValidationException::withMessages(['file' => self::NOT_CONFIGURED]);
        }
        $name = mb_substr(trim($file->getClientOriginalName()) ?: 'PDF', 0, 150);
        $text = $this->pdf->extract($file);
        $test = $this->tests->create(['title' => 'Import de '.$name], $by);
        $test->forceFill(['import_status' => OnlineTest::IMPORTING, 'import_error' => null])->save();
        ImportOnlineTestFromPdf::dispatch($test->id, $text->text)->onQueue('imports')->afterCommit();

        return $test;
    }

    /**
     * The AI's draft of a paper test (in the background job).
     *
     * @return array{title: string, content: array<string, mixed>}
     *
     * @throws AiUnavailable|AiInvalidResponse
     */
    public function draft(string $text): array
    {
        $answer = $this->ai->structured(self::system(), "Test papier :\n\n".$text, self::schema(), self::PURPOSE, ImportOnlineTestFromPdf::AI_TIMEOUT_SECONDS);

        return [
            'title' => mb_substr(trim((string) ($answer['title'] ?? '')) ?: 'Test importé', 0, 200),
            'content' => self::content($answer),
        ];
    }

    private static function system(): string
    {
        return 'Tu convertis un test de positionnement papier d\'un organisme de formation en test en ligne. '
            .'Rends les sections du test dans l\'ordre, avec pour chacune ses questions. Types : short_text (réponse courte), '
            .'long_text (réponse rédigée, texte à corriger), single_choice (une case parmi plusieurs), multiple_choice (plusieurs cases), '
            .'true_false (vrai ou faux), number (nombre à compléter), yes_no (oui ou non). Mets dans support_text le texte d\'appui '
            .'(article, texte à corriger, tableau décrit en texte) en 3 000 caractères au plus. Pour un calcul à plusieurs cases, crée une question number par case. '
            .'Ne donne jamais les bonnes réponses : answer vaut toujours null. Écris en français, sans inventer de question.';
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $text = fn (int $max): array => ['type' => ['string', 'null'], 'maxLength' => $max];

        return [
            'type' => 'object',
            'properties' => [
                'title' => $text(200),
                'sections' => ['type' => 'array', 'maxItems' => OnlineTestContent::MAX_SECTIONS, 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'maxLength' => 200],
                        'intro' => $text(5000),
                        'questions' => ['type' => 'array', 'maxItems' => 100, 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string', 'maxLength' => 30],
                                'label' => ['type' => 'string', 'maxLength' => 2000],
                                'support_text' => $text(3000),
                                'choices' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 500]],
                                'answer' => ['type' => ['string', 'boolean', 'null']],
                            ],
                            'required' => ['type', 'label', 'support_text', 'choices', 'answer'],
                            'additionalProperties' => false,
                        ]],
                    ],
                    'required' => ['title', 'intro', 'questions'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['title', 'sections'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The proposal in the content model: known types only, no answer, no
     * points (set by the organisme), a choice question with fewer than two
     * choices becomes a short answer.
     *
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    private static function content(array $answer): array
    {
        $sections = [];
        foreach ((array) ($answer['sections'] ?? []) as $section) {
            $questions = [];
            foreach ((array) ($section['questions'] ?? []) as $q) {
                $type = (string) ($q['type'] ?? '');
                $label = trim((string) ($q['label'] ?? ''));
                if (! in_array($type, OnlineTestContent::TYPES, true) || $label === '') {
                    continue;
                }
                $choices = array_values(array_filter(array_map(fn ($c) => trim((string) $c), (array) ($q['choices'] ?? [])), fn ($c) => $c !== ''));
                if (in_array($type, OnlineTestContent::CHOICE_TYPES, true) && count($choices) < 2) {
                    $type = 'short_text';
                    $choices = [];
                }
                $questions[] = [
                    'type' => $type,
                    'label' => $label,
                    'support_text' => $q['support_text'] ?? null,
                    'choices' => array_map(fn (string $c) => ['label' => $c], $choices),
                    'answer' => null,
                    'points' => 0,
                    'required' => true,
                ];
            }
            $title = trim((string) ($section['title'] ?? ''));
            $sections[] = ['title' => $title === '' ? 'Partie '.(count($sections) + 1) : $title, 'intro' => $section['intro'] ?? null, 'questions' => $questions];
        }

        return ['sections' => $sections];
    }
}
