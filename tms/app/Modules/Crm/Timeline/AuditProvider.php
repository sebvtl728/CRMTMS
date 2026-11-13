<?php

namespace App\Modules\Crm\Timeline;

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Crm\Organisations\OrganisationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Creation, modification (field names only), status changes, deletion and
 * restoration of the fiche itself. Values are never shown, except the status
 * (a business label, not personal data): the journal already holds personal
 * values masked, and the timeline must not be a way around that.
 */
final class AuditProvider implements TimelineProvider
{
    /** @var array<string, string> */
    private const FIELDS = [
        'name' => 'Raison sociale', 'siret' => 'SIRET', 'naf_code' => 'Code NAF',
        'legal_form_id' => 'Forme juridique', 'sector_id' => "Secteur d'activité", 'establishment_type_id' => "Type d'établissement",
        'company_size_id' => 'Taille', 'origin_id' => 'Origine', 'status' => 'Statut', 'responsible_user_id' => 'Responsable',
        'email' => 'E-mail', 'email_secondary' => 'E-mail secondaire', 'phone' => 'Téléphone', 'mobile' => 'Mobile', 'website' => 'Site web',
        'address_line1' => 'Adresse', 'address_line2' => "Complément d'adresse", 'postal_code' => 'Code postal', 'city' => 'Ville', 'country' => 'Pays',
        'notes_summary' => 'Notes', 'civility' => 'Civilité', 'first_name' => 'Prénom', 'last_name' => 'Nom',
        'birth_date' => 'Date de naissance', 'is_individual' => 'Particulier', 'project' => 'Projet',
        'professional_situation_id' => 'Situation professionnelle', 'funding_mode_id' => 'Mode de financement',
        'merged_into_id' => 'Fusion', 'source_system' => 'Source', 'source_id' => 'Identifiant source', 'anonymised_at' => 'Anonymisation',
    ];

    private const EVENTS = [
        'created', 'updated', 'deleted', 'restored',
        'crm.organisation_das_changed', 'crm.person_das_changed', 'crm.merged',
    ];

    public function entriesFor(Model $subject, ?TimelineCursor $before, int $limit): Collection
    {
        return AuditLog::query()->with('user')
            ->where('auditable_type', $subject->getMorphClass())->where('auditable_id', $subject->getKey())
            ->whereIn('event', self::EVENTS)
            ->tap(fn ($q) => Timeline::after($q, $before, 'audit'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (AuditLog $log) => new TimelineEntry(
                'audit',
                CarbonImmutable::instance($log->created_at),
                $this->title($log),
                null,
                $log->user?->name,
                ['id' => $log->id, 'event' => $log->event],
            ));
    }

    private function title(AuditLog $log): string
    {
        return match ($log->event) {
            'created' => 'Fiche créée',
            'deleted' => 'Fiche supprimée',
            'restored' => 'Fiche restaurée',
            'crm.organisation_das_changed', 'crm.person_das_changed' => 'DAS modifiés',
            'crm.merged' => 'Fiche en double fusionnée dans cette fiche',
            default => $this->updateTitle($log),
        };
    }

    private function updateTitle(AuditLog $log): string
    {
        $new = $log->new_values ?? [];
        $others = collect(array_keys($new))->reject(fn ($f) => $f === 'status')
            ->map(fn (string $f) => self::FIELDS[$f] ?? $f)->values()->all();
        $fields = $others === [] ? '' : implode(', ', $others);

        if (array_key_exists('status', $new)) {
            $from = $this->status($log->old_values['status'] ?? null);
            $to = $this->status($new['status']);

            return "Statut : {$from} → {$to}".($fields === '' ? '' : " ; autres champs : {$fields}");
        }

        return $fields === '' ? 'Fiche modifiée' : "Fiche modifiée (champs : {$fields})";
    }

    private function status(mixed $value): string
    {
        return is_string($value) ? (OrganisationStatus::tryFrom($value)?->label() ?? $value) : '—';
    }
}
