<?php

namespace App\Modules\Catalogue\Assistant;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Training;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * « Assistant IA » of the training form (create and edit pages):
 * catalogue.manage, limiter « ai » (20 requests an hour per user). Answers
 * a proposal as JSON; nothing is saved.
 */
class TrainingAssistantController extends Controller
{
    public function pdf(Request $request, TrainingAssistant $assistant, PdfText $pdf): JsonResponse
    {
        Gate::authorize('create', Training::class);
        $assistant->ensureConfigured();

        $request->validate(PdfText::rules(), PdfText::messages());
        $file = $request->file(PdfText::FIELD);
        assert($file instanceof UploadedFile);

        return response()->json($assistant->fromProgramme($pdf->extract($file)));
    }

    public function prompt(Request $request, TrainingAssistant $assistant): JsonResponse
    {
        Gate::authorize('create', Training::class);
        $assistant->ensureConfigured();

        $validated = $request->validate(
            ['prompt' => ['required', 'string', 'max:'.TrainingAssistant::MAX_PROMPT]],
            ['prompt.required' => 'Décrivez la formation souhaitée.', 'prompt.string' => 'Décrivez la formation souhaitée.',
                'prompt.max' => 'La demande ne doit pas dépasser 2 000 caractères.'],
        );

        return response()->json($assistant->fromRequest(trim((string) $validated['prompt'])));
    }
}
