/** A session as the list, the calendar and the session page receive it (SessionRows). */
export interface SessionRow {
    id: string;
    number: string;
    training: { id: string; code: string | null; title: string; is_active: boolean } | null;
    das: string | null;
    kind: 'intra' | 'inter';
    kind_label: string;
    organisation: { id: string; label: string; trashed: boolean } | null;
    starts_on: string;
    ends_on: string;
    /** French decimal (« 21,00 »). */
    planned_hours: string | null;
    location_kind: 'address' | 'client' | 'remote';
    location_label: string;
    location_text: string | null;
    capacity: number | null;
    responsible: string | null;
    /** Effective state of the Paris day. */
    status: 'planned' | 'confirmed' | 'running' | 'done' | 'cancelled';
    status_label: string;
    trashed: boolean;
}

export function sessionStatusVariant(status: SessionRow['status']): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (status) {
        case 'running':
            return 'default';
        case 'confirmed':
            return 'secondary';
        case 'cancelled':
            return 'destructive';
        default:
            return 'outline';
    }
}

/** « 07/12/2026 » or « 07/12/2026 → 09/12/2026 ». */
export function sessionDates(row: Pick<SessionRow, 'starts_on' | 'ends_on'>): string {
    const day = (v: string) => v.split('-').reverse().join('/');

    return row.starts_on === row.ends_on ? day(row.starts_on) : `${day(row.starts_on)} → ${day(row.ends_on)}`;
}

/** « Adresse : 12 rue… », « Chez le client », « Distanciel : lien ». */
export function sessionLocation(row: Pick<SessionRow, 'location_kind' | 'location_label' | 'location_text'>): string {
    if (row.location_kind === 'address') return row.location_text ?? '—';

    return row.location_text ? `${row.location_label} : ${row.location_text}` : row.location_label;
}
