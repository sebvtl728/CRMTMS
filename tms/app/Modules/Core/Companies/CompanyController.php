<?php

namespace App\Modules\Core\Companies;

use App\Http\Controllers\Controller;
use App\Rules\Siret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Legal entities of the current organisme (permission companies.manage).
 */
class CompanyController extends Controller
{
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
        ], [
            'capital_amount.required' => 'Le capital social est obligatoire pour cette forme juridique (sauf capital variable).',
            'capital_amount.gt' => 'Le capital doit être strictement supérieur à 0.',
            'siret.required' => 'Le numéro SIRET est obligatoire.',
            'scop_underlying_form.required' => 'Indiquez la forme sous-jacente de la coopérative (SARL, SA ou SAS).',
            'vat_number.regex' => 'Le numéro de TVA intracommunautaire est invalide (ex. FR12345678901).',
        ]);

        if (! (LegalForm::from($data['legal_form'])->isCooperative())) {
            $data['scop_underlying_form'] = null;
        }
        $data['address_country'] = mb_strtoupper($data['address_country'] ?? 'FR');

        return $data;
    }
}
