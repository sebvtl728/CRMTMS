<?php

namespace App\Modules\Documents\Texts;

use App\Http\Controllers\Controller;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Users\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration › Modèles de documents (settings.manage): the texts of each
 * training document, for the organisme or one company.
 */
class DocumentTextController extends Controller
{
    public function __construct(private readonly DocumentTexts $texts) {}

    public function index(Request $request): Response
    {
        $type = DocumentType::tryFrom((string) $request->query('type')) ?? DocumentType::Convention;
        $companyId = self::companyId($request->query('company'));
        $stored = $this->texts->stored($type, $companyId);
        $effective = $this->texts->resolve($type, $companyId);

        return Inertia::render('admin/document-texts/index', [
            'types' => DocumentType::options(),
            'type' => $type->value,
            'companies' => Company::query()->orderBy('name')->get(['id', 'name'])->map(fn (Company $c) => ['value' => $c->id, 'label' => $c->name])->values()->all(),
            'company_id' => $companyId,
            'fields' => DocumentTexts::FIELDS,
            'blocks' => collect(DocumentTexts::blocks($type))->map(fn (array $b, string $key) => [
                'key' => $key,
                'label' => $b['label'],
                'required' => $b['required'],
                'default' => $b['default'],
                'body' => $effective[$key],
                'overridden' => array_key_exists($key, $stored),
            ])->values()->all(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, string $type, string $block): RedirectResponse
    {
        $documentType = self::type($type, $block);
        $this->texts->save($documentType, self::companyId($request->input('company_id')), $block, (string) $request->input('body', ''), self::user($request));

        return back()->with('status', 'Texte enregistré.');
    }

    public function destroy(Request $request, string $type, string $block): RedirectResponse
    {
        $documentType = self::type($type, $block);
        $this->texts->reset($documentType, self::companyId($request->input('company_id')), $block, self::user($request));

        return back()->with('status', 'Texte d\'origine rétabli.');
    }

    private static function type(string $type, string $block): DocumentType
    {
        $documentType = DocumentType::tryFrom($type) ?? abort(404);
        abort_unless(array_key_exists($block, DocumentTexts::blocks($documentType)), 404);

        return $documentType;
    }

    /** A company of the organisme, else null (the organisme's texts). */
    private static function companyId(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) && Company::query()->whereKey($value)->exists() ? $value : null;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
