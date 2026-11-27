<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\Companies\Brand;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Documents\PdfRenderer;
use App\Modules\Core\References\ReferenceItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The programme of a training as a PDF, rendered from its sheet on demand
 * (never archived: it always shows the current sheet), dated with the last
 * change of the sheet or of its modules (Qualiopi indicator 1: information
 * to the public, dated). Header: the default company of the organisme.
 */
final class TrainingProgramme
{
    public function __construct(private readonly PdfRenderer $renderer, private readonly CompanyBranding $branding) {}

    /**
     * Day (Paris) of the last change of the sheet; module changes touch the training.
     */
    public static function updatedOn(Training $training): CarbonImmutable
    {
        return CarbonImmutable::instance($training->updated_at ?? $training->created_at ?? now())
            ->setTimezone((string) config('app.timezone'))->startOfDay();
    }

    public static function fileName(Training $training): string
    {
        return 'programme-'.Str::slug($training->code ?? $training->title).'.pdf';
    }

    public function pdf(Training $training): string
    {
        $updated = self::updatedOn($training)->format('d/m/Y');

        return $this->renderer->fromHtml(
            $this->html($training, $updated),
            view('documents.document-footer', ['label' => 'Programme « '.$training->title.' » - mis à jour le '.$updated])->render(),
        );
    }

    private function html(Training $training, string $updated): string
    {
        $training->loadMissing(['das', 'fundingModes']);
        $company = Brand::company();
        $modules = TrainingModule::query()->where('training_id', $training->id)->orderBy('position')->orderBy('id')->get();
        $modalities = ReferenceItem::query()->withTrashed()->whereKey($modules->pluck('modality_id')->filter()->unique()->all())->pluck('label', 'id');

        return view('documents.training-programme', [
            'company' => $company === null ? null : [
                'name' => $company->name,
                'logo' => $this->branding->logoDataUri($company),
                'address' => trim(implode(' ', array_filter([$company->address_line1, $company->address_line2, $company->address_postal_code, $company->address_city]))),
                'nda' => $company->nda_number,
                'siret' => $company->siret,
                'email' => $company->email,
                'phone' => $company->phone,
            ],
            'training' => [
                'title' => $training->title,
                'code' => $training->code,
                'rncp_code' => $training->rncp_code,
                'das' => $training->das?->label,
                'duration_hours' => $training->duration_hours,
                'duration_days' => $training->duration_days,
                'centre_hours' => $training->centre_hours,
                'company_hours' => $training->company_hours,
                'price_company' => $training->price_company,
                'price_individual' => $training->price_individual,
                'funding_modes' => $training->fundingModes->pluck('label')->all(),
                'description' => $training->description,
                'objectives' => array_values(array_filter($training->objectives ?? [], fn ($o) => trim((string) $o) !== '')),
                'audience' => $training->audience,
                'prerequisites' => $training->prerequisites,
                'methods' => $training->methods,
                'evaluation' => $training->evaluation,
                'sanction' => $training->sanction,
                'accessibility' => $training->accessibility,
                'access_terms' => $training->access_terms,
                'results' => $training->results,
                'custom_fields' => TrainingCustomFields::filled($training),
            ],
            'modules' => $modules->map(fn (TrainingModule $m) => [
                'title' => $m->title,
                'duration_hours' => $m->duration_hours,
                'modality' => $m->modality_id === null ? null : ($modalities[$m->modality_id] ?? null),
                'content' => $m->content,
            ])->all(),
            'updated' => $updated,
        ])->render();
    }
}
