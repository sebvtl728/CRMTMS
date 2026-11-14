<?php

namespace App\Modules\Core\Companies;

use App\Http\Controllers\Controller;
use App\Rules\Siret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Legal entities of the current organisme (permission companies.manage).
 */
class CompanyController extends Controller
{
    public function __construct(private readonly CompanyBranding $branding) {}

    public function index(Request $request): Response
    {
        return Inertia::render('admin/companies/index', [
            'status' => $request->session()->get('status'),
            'companies' => Company::query()->orderByDesc('is_default')->orderBy('name')->get()->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'legal_form' => $c->legal_form->label(),
                'siret' => $c->siret,
                'vat_regime' => $c->vat_regime->label(),
                'is_default' => $c->is_default,
                'mentions' => LegalMentions::for($c),
            ]),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(Company $company): Response
    {
        return $this->form($company);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        // The first company of an organisme is its default.
        $data['is_default'] = ($data['is_default'] ?? false) || ! Company::query()->exists();

        Company::create($data);

        return to_route('admin.companies.index')->with('status', 'Société créée.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $data = $this->validated($request);
        // The default flag can only be moved by electing another company.
        $data['is_default'] = $company->is_default || ($data['is_default'] ?? false);

        $company->update($data);

        return to_route('admin.companies.index')->with('status', 'Société enregistrée.');
    }

    /**
     * Uploads (or replaces) the logo printed on the company's quotes.
     */
    public function storeLogo(Request $request, Company $company): RedirectResponse
    {
        $request->validate([
            'logo' => [
                'required', 'file', 'max:'.CompanyBranding::MAX_KILOBYTES,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value instanceof UploadedFile && CompanyBranding::detectedType($value) === null) {
                        $fail(CompanyBranding::TYPE_MESSAGE);
                    }
                },
            ],
        ], [
            'logo.required' => 'Choisissez une image.',
            'logo.file' => 'Le fichier envoyé est invalide.',
            'logo.uploaded' => 'Le logo n\'a pas pu être envoyé (taille maximale : 1 Mo).',
            'logo.max' => 'Le logo ne doit pas dépasser 1 Mo.',
        ]);

        $file = $request->file('logo');
        assert($file instanceof UploadedFile);
        $this->branding->storeLogo($company, $file);

        return back()->with('status', 'Logo enregistré.');
    }

    public function destroyLogo(Company $company): RedirectResponse
    {
        $this->branding->deleteLogo($company);

        return back()->with('status', 'Logo supprimé.');
    }

    /**
     * Legal mentions of unsaved input, for the live preview of the form.
     */
    public function preview(Request $request): JsonResponse
    {
        $input = $this->normalise($request);
        $company = new Company([
            'name' => (string) ($input['name'] ?? ''),
            'legal_form' => LegalForm::tryFrom((string) ($input['legal_form'] ?? '')) ?? LegalForm::Autre,
            'scop_underlying_form' => in_array($input['scop_underlying_form'] ?? null, LegalForm::underlyingForms(), true) ? $input['scop_underlying_form'] : null,
            'capital_amount' => is_numeric($input['capital_amount'] ?? null) ? $input['capital_amount'] : null,
            'capital_variable' => filter_var($input['capital_variable'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'siret' => is_string($input['siret'] ?? null) ? $input['siret'] : null,
            'rcs_city' => is_string($input['rcs_city'] ?? null) ? $input['rcs_city'] : null,
        ]);

        return response()->json(['mentions' => LegalMentions::for($company)]);
    }

    private function form(?Company $company): Response
    {
        return Inertia::render('admin/companies/edit', [
            'company' => $company === null ? null : [
                'id' => $company->id,
                'name' => $company->name,
                'legal_form' => $company->legal_form->value,
                'scop_underlying_form' => $company->scop_underlying_form,
                'capital_amount' => $company->capital_amount,
                'capital_variable' => $company->capital_variable,
                'siret' => $company->siret,
                'rcs_city' => $company->rcs_city,
                'vat_number' => $company->vat_number,
                'vat_regime' => $company->vat_regime->value,
                'nda_number' => $company->nda_number,
                'address_line1' => $company->address_line1,
                'address_line2' => $company->address_line2,
                'address_postal_code' => $company->address_postal_code,
                'address_city' => $company->address_city,
                'address_country' => $company->address_country,
                'email' => $company->email,
                'phone' => $company->phone,
                'is_default' => $company->is_default,
                'quote_prefix' => $company->quote_prefix,
                'quote_validity_days' => $company->quote_validity_days,
                'quote_terms' => $company->quote_terms,
                'quote_footer' => $company->quote_footer,
                'logo' => $this->branding->logoDataUri($company),
            ],
            'mentions' => $company === null ? '' : LegalMentions::for($company),
            'legalForms' => LegalForm::options(),
            'underlyingForms' => array_map(fn (string $f) => ['value' => $f, 'label' => mb_strtoupper($f)], LegalForm::underlyingForms()),
            'vatRegimes' => VatRegime::options(),
            'vatExemptionNotice' => LegalMentions::vatExemptionNotice(),
        ]);
    }

    private function needsFixedCapital(Request $request): bool
    {
        $form = LegalForm::tryFrom((string) $request->input('legal_form'));

        return $form !== null
            && in_array($form, [LegalForm::Sarl, LegalForm::Sas, LegalForm::Sasu, LegalForm::Sa, LegalForm::Eurl, LegalForm::Scop, LegalForm::Scic], true)
            && ! filter_var($request->input('capital_variable', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalise(Request $request): array
    {
        $input = $request->all();

        foreach (['siret', 'vat_number'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = preg_replace('/\s+/', '', $input[$key]);
            }
        }
        if (is_string($input['vat_number'] ?? null)) {
            $input['vat_number'] = mb_strtoupper($input['vat_number']);
        }
        if (is_string($input['quote_prefix'] ?? null)) {
            $input['quote_prefix'] = mb_strtoupper(trim($input['quote_prefix']));
        }
        if (is_string($input['capital_amount'] ?? null)) {
            $input['capital_amount'] = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $input['capital_amount']);
        }
        foreach (['siret', 'vat_number', 'capital_amount'] as $key) {
            if (($input[$key] ?? null) === '') {
                $input[$key] = null;
            }
        }

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $request->merge($this->normalise($request));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_form' => ['required', Rule::enum(LegalForm::class)],
            'scop_underlying_form' => [
                Rule::requiredIf(fn () => LegalForm::tryFrom((string) $request->input('legal_form'))?->isCooperative() ?? false),
                'nullable', Rule::in(LegalForm::underlyingForms()),
            ],
            // A fixed capital must be stated on quotes/invoices of capital-based forms.
            'capital_amount' => [
                Rule::requiredIf(fn () => $this->needsFixedCapital($request)),
                'nullable', 'numeric', $request->input('capital_variable') ? 'min:0' : 'gt:0', 'max:999999999999',
            ],
            'capital_variable' => ['boolean'],
            // Required here (not at database level): see Company.
            'siret' => ['required', 'string', new Siret],
            'rcs_city' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'regex:/^[A-Z]{2}[A-Z0-9]{2,18}$/'],
            'vat_regime' => ['required', Rule::enum(VatRegime::class)],
            'nda_number' => ['nullable', 'string', 'max:30'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'address_postal_code' => ['nullable', 'string', 'max:10'],
            'address_city' => ['nullable', 'string', 'max:255'],
            'address_country' => ['nullable', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_default' => ['boolean'],
            // Quotes (absent from a request: unchanged, or the defaults on creation).
            'quote_prefix' => ['sometimes', 'required', 'string', 'regex:/^[A-Z0-9]{1,10}$/'],
            'quote_validity_days' => ['sometimes', 'required', 'integer', 'between:1,365'],
            'quote_terms' => ['nullable', 'string', 'max:5000'],
            'quote_footer' => ['nullable', 'string', 'max:5000'],
        ], [
            'capital_amount.required' => 'Le capital social est obligatoire pour cette forme juridique (sauf capital variable).',
            'capital_amount.gt' => 'Le capital doit être strictement supérieur à 0.',
            'siret.required' => 'Le numéro SIRET est obligatoire.',
            'scop_underlying_form.required' => 'Indiquez la forme sous-jacente de la coopérative (SARL, SA ou SAS).',
            'vat_number.regex' => 'Le numéro de TVA intracommunautaire est invalide (ex. FR12345678901).',
            'quote_prefix.required' => 'Le préfixe de numérotation est obligatoire.',
            'quote_prefix.string' => 'Le préfixe ne peut contenir que des lettres majuscules et des chiffres (10 caractères au plus).',
            'quote_prefix.regex' => 'Le préfixe ne peut contenir que des lettres majuscules et des chiffres (10 caractères au plus).',
            'quote_validity_days.required' => 'La durée de validité doit être comprise entre 1 et 365 jours.',
            'quote_validity_days.integer' => 'La durée de validité doit être comprise entre 1 et 365 jours.',
            'quote_validity_days.between' => 'La durée de validité doit être comprise entre 1 et 365 jours.',
            'quote_terms.string' => 'Les conditions doivent être un texte.',
            'quote_terms.max' => 'Les conditions ne peuvent pas dépasser 5 000 caractères.',
            'quote_footer.string' => 'Le pied de page doit être un texte.',
            'quote_footer.max' => 'Le pied de page ne peut pas dépasser 5 000 caractères.',
        ]);

        if (! (LegalForm::from($data['legal_form'])->isCooperative())) {
            $data['scop_underlying_form'] = null;
        }
        $data['address_country'] = mb_strtoupper($data['address_country'] ?? 'FR');

        return $data;
    }
}
