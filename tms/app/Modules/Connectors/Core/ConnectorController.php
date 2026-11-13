<?php

namespace App\Modules\Connectors\Core;

use App\Http\Controllers\Controller;
use App\Modules\Core\Companies\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Administration of connectors (permission connectors.manage): configuration
 * per organisme / company, connection test, run journal and replay. Secrets
 * are write-only: only the names of the secrets that hold a value reach the
 * interface.
 */
class ConnectorController extends Controller
{
    /** Settings that designate the remote server or how it is reached (a downgrade of the encryption would send the password in clear): changing one requires the secrets again. */
    private const SERVER_SETTINGS = ['host', 'port', 'encryption'];

    public function __construct(private readonly ConnectorRegistry $registry) {}

    public function index(Request $request): Response
    {
        $configs = ConnectorConfig::query()->get()->groupBy('connector_key');
        $connectors = $this->registry->all();

        return Inertia::render('admin/connectors/index', [
            'status' => $request->session()->get('status'),
            'connectors' => collect($connectors)->map(fn (Connector $c, string $key) => [
                'key' => $key,
                'label' => $c::label(),
                'kind' => $c::kind()->label(),
                'configured' => $configs->has($key),
                'enabled' => (bool) $configs->get($key)?->contains('enabled', true),
            ])->values(),
            'runs' => ConnectorRun::query()->with('config')->latest('started_at')->limit(25)->get()->map(fn (ConnectorRun $r) => [
                'id' => $r->id,
                'connector' => ($key = $r->config->connector_key) && isset($connectors[$key]) ? $connectors[$key]::label() : $key,
                'direction' => $r->direction,
                'status' => $r->status,
                'summary' => $r->summary,
                'error' => $r->error,
                'started_at' => $r->started_at->toIso8601String(),
                'finished_at' => $r->finished_at?->toIso8601String(),
                'retryable' => $this->isRetryable($r),
            ])->values(),
        ]);
    }

    public function edit(Request $request, string $key): Response
    {
        $connector = $this->connector($key);
        $companyId = $this->scopeFromQuery($request);
        $config = $this->find($key, $companyId);

        return Inertia::render('admin/connectors/edit', [
            'status' => $request->session()->get('status'),
            'connector' => [
                'key' => $key,
                'label' => $connector::label(),
                'kind' => $connector::kind()->label(),
                'settings' => array_keys($connector->settingsRules()),
                'secrets' => $connector->secretKeys(),
                'labels' => $connector instanceof HasFormLabels ? $connector->labels() : [],
            ],
            'companyId' => $companyId,
            'companies' => Company::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name'])->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'config' => $config === null ? null : [
                'id' => $config->id,
                'enabled' => $config->enabled,
                'settings' => $config->settings,
                'secrets_set' => $config->secretsSet($connector->secretKeys()),
            ],
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $connector = $this->connector($key);

        $rules = ['settings' => ['required', 'array']];
        foreach ($connector->settingsRules() as $name => $fieldRules) {
            $rules["settings.{$name}"] = $fieldRules;
        }
        foreach ($connector->secretKeys() as $secret) {
            $rules["secrets.{$secret}"] = ['nullable', 'string', 'max:1000'];
        }

        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', fn ($attr, $value, $fail) => $value === null || Company::query()->whereKey($value)->exists() ? null : $fail('Société inconnue.')],
            'enabled' => ['required', 'boolean'],
            ...$rules,
        ], ['settings.required' => 'Les paramètres sont obligatoires.']);

        $companyId = $data['company_id'] ?? null;
        $config = $this->find($key, $companyId) ?? new ConnectorConfig(['connector_key' => $key, 'company_id' => $companyId]);

        $this->requireSecretsWhenServerMoves($connector, $config, $data);

        // An empty secret field keeps the stored value; clearing is explicit (clearSecret).
        $secrets = $config->secrets ?? [];
        foreach ($connector->secretKeys() as $secret) {
            $value = $data['secrets'][$secret] ?? null;
            if (is_string($value) && $value !== '') {
                $secrets[$secret] = $value;
            }
        }

        $config->fill([
            'enabled' => (bool) $data['enabled'],
            'settings' => array_intersect_key($data['settings'], $connector->settingsRules()),
            'secrets' => $secrets === [] ? null : $secrets,
        ])->save();

        return to_route('admin.connectors.edit', ['key' => $key, 'company' => $companyId])->with('status', 'Configuration enregistrée.');
    }

    /**
     * Stored credentials never follow a change of server: when the host or the
     * port changes, every stored secret must be typed again (otherwise an
     * edited host would receive the saved password at the next send).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function requireSecretsWhenServerMoves(Connector $connector, ConnectorConfig $config, array $data): void
    {
        if (! $config->exists || ($config->secrets ?? []) === []) {
            return;
        }

        $moved = false;
        foreach (self::SERVER_SETTINGS as $setting) {
            if (array_key_exists($setting, $config->settings) && (string) $config->settings[$setting] !== (string) ($data['settings'][$setting] ?? '')) {
                $moved = true;
            }
        }
        if (! $moved) {
            return;
        }

        $missing = [];
        foreach ($connector->secretKeys() as $secret) {
            $value = $data['secrets'][$secret] ?? null;
            if (isset($config->secrets[$secret]) && (! is_string($value) || $value === '')) {
                $missing["secrets.{$secret}"] = 'Le serveur a changé : ressaisissez ce champ (les identifiants enregistrés ne sont pas transmis à un autre serveur).';
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    public function clearSecret(Request $request, string $key, string $secret): RedirectResponse
    {
        $connector = $this->connector($key);
        abort_unless(in_array($secret, $connector->secretKeys(), true), 404);

        $companyId = $this->scopeFromQuery($request);
        $config = $this->find($key, $companyId) ?? abort(404);

        $secrets = $config->secrets ?? [];
        unset($secrets[$secret]);
        $config->update(['secrets' => $secrets === [] ? null : $secrets]);

        return to_route('admin.connectors.edit', ['key' => $key, 'company' => $companyId])->with('status', 'Secret effacé.');
    }

    public function test(Request $request, string $key): JsonResponse
    {
        $connector = $this->connector($key);
        $config = $this->find($key, $this->scopeFromQuery($request));

        if ($config === null) {
            return response()->json(['ok' => false, 'message' => 'Enregistrez d\'abord la configuration avant de la tester.'], 422);
        }

        $result = null;
        ConnectorRun::track($config, 'out', function () use ($connector, $config, &$result): string {
            $result = $connector->test($config);

            return $result->ok ? $result->message : throw new RuntimeException($result->message);
        });

        return response()->json(['ok' => $result->ok ?? false, 'message' => $result->message ?? 'Test impossible.']);
    }

    public function retry(ConnectorRun $run): RedirectResponse|JsonResponse
    {
        if (! $run->failed()) {
            throw ValidationException::withMessages(['run' => 'Seule une exécution en échec peut être relancée.']);
        }
        if ($run->payload_ref === null) {
            throw ValidationException::withMessages(['run' => 'Cette exécution ne peut pas être relancée : aucune donnée n\'a été conservée.']);
        }

        $connector = $this->registry->has($run->config->connector_key) ? $this->registry->make($run->config->connector_key) : null;
        if (! $connector instanceof Retryable) {
            throw ValidationException::withMessages(['run' => 'Ce connecteur ne permet pas de relancer une exécution.']);
        }

        $new = ConnectorRun::track($run->config, $run->direction, function () use ($connector, $run): string {
            $connector->retry($run);

            return 'Relance de l\'exécution du '.$run->started_at->format('d/m/Y H:i');
        }, $run->payload_ref);

        return to_route('admin.connectors.index')->with('status', $new->status === 'success' ? 'Exécution relancée.' : 'La relance a échoué.');
    }

    private function isRetryable(ConnectorRun $run): bool
    {
        return $run->failed()
            && $run->payload_ref !== null
            && $this->registry->has($run->config->connector_key)
            && $this->registry->make($run->config->connector_key) instanceof Retryable;
    }

    private function connector(string $key): Connector
    {
        return $this->registry->has($key) ? $this->registry->make($key) : abort(404);
    }

    private function scopeFromQuery(Request $request): ?string
    {
        $company = $request->query('company');
        if (! is_string($company) || $company === '') {
            return null;
        }

        return Str::isUuid($company) && Company::query()->whereKey($company)->exists() ? $company : abort(404);
    }

    private function find(string $key, ?string $companyId): ?ConnectorConfig
    {
        return ConnectorConfig::query()->where('connector_key', $key)->where('company_id', $companyId)->first();
    }
}
