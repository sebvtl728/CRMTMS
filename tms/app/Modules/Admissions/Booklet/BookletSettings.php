<?php

namespace App\Modules\Admissions\Booklet;

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Settings of the apprenticeship booklet, in tenants.settings['admissions']
 * ['booklet']: the CFA team printed in it, the word of the director and the
 * annexes (PDFs of the organisme merged at the end: statut, règlements…),
 * stored in the « admissions » area under booklet/. Read again at each call.
 */
final class BookletSettings
{
    public const MAX_TEAM = 15;

    public const MAX_ANNEXES = 8;

    public const MAX_ANNEX_KB = 20480;

    /**
     * @return array{team: list<array{role: string, name: string, phone: ?string, email: ?string}>, director_note: ?string, annexes: list<array{id: string, title: string, size: int}>}
     */
    public static function all(): array
    {
        $booklet = data_get(self::tenant()->settings ?? [], 'admissions.booklet', []);
        $booklet = is_array($booklet) ? $booklet : [];

        return [
            'team' => array_values(array_filter($booklet['team'] ?? [], 'is_array')),
            'director_note' => is_string($booklet['director_note'] ?? null) ? $booklet['director_note'] : null,
            'annexes' => array_values(array_filter($booklet['annexes'] ?? [], 'is_array')),
        ];
    }

    /**
     * @param  array<string, mixed>  $values  team, director_note
     *
     * @throws ValidationException
     */
    public static function save(array $values): void
    {
        $data = Validator::make($values, [
            'team' => ['present', 'nullable', 'array', 'max:'.self::MAX_TEAM],
            'team.*.role' => ['required', 'string', 'max:100'],
            'team.*.name' => ['required', 'string', 'max:200'],
            'team.*.phone' => ['nullable', 'string', 'max:30'],
            'team.*.email' => ['nullable', 'email', 'max:255'],
            'director_note' => ['nullable', 'string', 'max:3000'],
        ], [
            'team.max' => self::MAX_TEAM.' lignes au plus.',
            'team.*.role.required' => 'Indiquez le rôle.',
            'team.*.name.required' => 'Indiquez le nom.',
            'team.*.email.email' => 'Adresse e-mail invalide.',
            'director_note.max' => '3 000 caractères au plus.',
        ])->validate();

        $clean = fn (mixed $v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $team = array_map(fn (array $row) => [
            'role' => trim((string) $row['role']),
            'name' => trim((string) $row['name']),
            'phone' => $clean($row['phone'] ?? null),
            'email' => $clean($row['email'] ?? null),
        ], array_values($data['team'] ?? []));

        self::write(fn (array $booklet) => [...$booklet, 'team' => $team, 'director_note' => $clean($data['director_note'] ?? null)]);
    }

    /**
     * Adds a PDF annex (content checked: « %PDF- » header), last in order.
     *
     * @throws ValidationException
     */
    public static function addAnnex(string $title, mixed $file): void
    {
        Validator::make(['title' => $title, 'file' => $file], [
            'title' => ['required', 'string', 'max:150'],
            'file' => ['required', 'file', 'max:'.self::MAX_ANNEX_KB],
        ], [
            'title.required' => 'Indiquez le titre de l\'annexe.',
            'file.required' => 'Choisissez un fichier PDF.',
            'file.max' => 'Le fichier dépasse 20 Mo.',
        ])->validate();
        assert($file instanceof UploadedFile);
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 5);
        if ($head !== '%PDF-') {
            throw ValidationException::withMessages(['file' => 'Le fichier n\'est pas un PDF.']);
        }
        if (count(self::all()['annexes']) >= self::MAX_ANNEXES) {
            throw ValidationException::withMessages(['file' => self::MAX_ANNEXES.' annexes au plus.']);
        }

        $id = (string) Str::uuid7();
        Storage::disk(TenantStorage::DISK)->put(self::annexPath($id), (string) file_get_contents($file->getRealPath()));
        self::write(fn (array $booklet) => [...$booklet, 'annexes' => [...array_values($booklet['annexes'] ?? []), ['id' => $id, 'title' => trim($title), 'size' => (int) $file->getSize()]]]);
    }

    public static function removeAnnex(string $id): void
    {
        if (! Str::isUuid($id)) {
            return;
        }
        self::write(fn (array $booklet) => [...$booklet, 'annexes' => array_values(array_filter($booklet['annexes'] ?? [], fn ($a) => ($a['id'] ?? null) !== $id))]);
        Storage::disk(TenantStorage::DISK)->delete(self::annexPath($id));
    }

    public static function annexPath(string $id): string
    {
        return TenantStorage::path('admissions', 'booklet/'.$id.'.pdf');
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $change
     */
    private static function write(\Closure $change): void
    {
        DB::transaction(function () use ($change): void {
            $tenant = Tenant::query()->whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            $settings = $tenant->settings ?? [];
            $booklet = data_get($settings, 'admissions.booklet', []);
            $settings['admissions']['booklet'] = $change(is_array($booklet) ? $booklet : []);
            $tenant->forceFill(['settings' => $settings])->save();
        });
    }

    private static function tenant(): Tenant
    {
        return Tenant::query()->whereKey(app(TenantContext::class)->id())->firstOrFail();
    }
}
