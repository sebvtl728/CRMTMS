<?php

namespace App\Modules\Core\Mail;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Users\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * « Modèles d'e-mails » (settings.manage): the organisme's texts of the
 * TMS e-mails; system templates are shown read only.
 */
class MailTemplateController extends Controller
{
    public function __construct(private readonly MailTemplates $templates) {}

    public function index(Request $request): Response
    {
        return Inertia::render('admin/mail-templates/index', [
            'templates' => array_map(fn (MailTemplateDefinition $d) => [
                'key' => $d->key,
                'label' => $d->label,
                'description' => $d->description,
                'editable' => $d->editable,
                'customised' => $this->templates->get($d->key)['customised'],
            ], MailTemplateRegistry::all()),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function edit(Request $request, string $key): Response
    {
        $definition = $this->definition($key);
        $current = $this->templates->get($key);

        return Inertia::render('admin/mail-templates/edit', [
            'template' => [
                'key' => $definition->key,
                'label' => $definition->label,
                'description' => $definition->description,
                'editable' => $definition->editable,
                'customised' => $current['customised'],
                'subject' => $current['subject'],
                'body' => $current['body'],
                'variables' => array_map(fn (string $name, array $v) => ['name' => $name, 'description' => $v[0], 'example' => $v[1]], array_keys($definition->variables), $definition->variables),
            ],
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $this->editable($key);
        [$subject, $body] = $this->validated($request, $key);
        $this->templates->save($key, $subject, $body);

        return back()->with('status', 'Modèle enregistré.');
    }

    public function destroy(string $key): RedirectResponse
    {
        $this->editable($key);
        $this->templates->reset($key);

        return back()->with('status', 'Modèle par défaut rétabli.');
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        $this->editable($key);
        [$subject, $body] = $this->validated($request, $key);
        $sample = MailTemplates::sample($key);

        return response()->json(['subject' => MailTemplates::fill($subject, $sample), 'body' => MailTemplates::fill($body, $sample)]);
    }

    /**
     * The typed text (not saved) with sample values, to the administrator.
     */
    public function test(Request $request, string $key): RedirectResponse
    {
        $this->editable($key);
        [$subject, $body] = $this->validated($request, $key);
        $sample = MailTemplates::sample($key);
        $user = $request->user();
        assert($user instanceof User);

        try {
            TenantMailer::for()->to($user->email)->send(new MailTemplateTestMail(MailTemplates::fill($subject, $sample), MailTemplates::fill($body, $sample)));
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages(['test' => "L'e-mail de test n'est pas parti. Vérifiez la messagerie dans Connecteurs."]);
        }

        return back()->with('status', "E-mail de test envoyé à {$user->email}.");
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function validated(Request $request, string $key): array
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:'.MailTemplates::SUBJECT_MAX],
            'body' => ['required', 'string', 'max:10000'],
        ], [
            'subject.required' => "Indiquez l'objet.",
            'subject.max' => "L'objet ne doit pas dépasser 200 caractères.",
            'body.required' => 'Indiquez le texte.',
            'body.max' => 'Le texte ne doit pas dépasser 10 000 caractères.',
        ]);
        $subject = (string) $data['subject'];
        $body = str_replace("\r\n", "\n", (string) $data['body']);
        $errors = MailTemplates::errors($key, $subject, $body);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$subject, $body];
    }

    private function definition(string $key): MailTemplateDefinition
    {
        return MailTemplateRegistry::find($key) ?? abort(404);
    }

    private function editable(string $key): void
    {
        abort_unless($this->definition($key)->editable, 403);
    }
}
