/** A session as the list, the calendar and the session page receive it (SessionRows). */
export interface SessionRow {
    id: string;
    number: string;
    /** « Commis de cuisine – entrée du 30 novembre 2026 » (optional). */
    title: string | null;
    /** « <intitulé> (S-…) », or « <formation> (S-…) » without a title. */
    label: string;
    training: { id: string; code: string | null; title: string; is_active: boolean } | null;
    das: string | null;
    kind: 'intra' | 'inter' | 'individual';
    kind_label: string;
    /** Label of the « Modalités de formation » value. */
    modality: string | null;
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
    /** Calendar only: the days it shows (séance days, else working days). */
    days?: string[];
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

const pad = (n: number) => String(n).padStart(2, '0');

/** A calendar day (« YYYY-MM-DD ») moved by n days, without time zone. */
export function addDays(day: string, n: number): string {
    const [y, m, d] = day.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d + n));

    return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

/** ISO day of the week: 1 = Monday … 7 = Sunday. */
export function isoWeekday(day: string): number {
    const [y, m, d] = day.split('-').map(Number);

    return ((new Date(Date.UTC(y, m - 1, d)).getUTCDay() + 6) % 7) + 1;
}

/** The Monday of the week of a day. */
export function mondayOf(day: string): string {
    return addDays(day, 1 - isoWeekday(day));
}

/**
 * Days of the weeks covering the month (Monday first), as YYYY-MM-DD strings
 * computed without time zone (a calendar day, not an instant).
 */
export function monthGrid(month: string): { day: string; inMonth: boolean }[][] {
    const [y, m] = month.split('-').map(Number);
    const last = `${month}-${pad(new Date(Date.UTC(y, m, 0)).getUTCDate())}`;
    const end = addDays(mondayOf(last), 6);
    const weeks: { day: string; inMonth: boolean }[][] = [];
    for (let start = mondayOf(`${month}-01`); start <= end; start = addDays(start, 7)) {
        weeks.push(Array.from({ length: 7 }, (_, i) => addDays(start, i)).map((day) => ({ day, inMonth: day.slice(0, 7) === month })));
    }

    return weeks;
}

export const WEEKDAYS_SHORT = ['Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.', 'Dim.'];

export const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

export interface ClosureRow {
    starts_on: string;
    ends_on: string;
    label: string;
}

/** What shades a calendar day: weekend, public holiday, closure (labelled). */
export function dayShade(day: string, holidays: Record<string, string>, closures: ClosureRow[]): { off: boolean; label: string | null } {
    const holiday = holidays[day];
    if (holiday) return { off: true, label: `Férié : ${holiday}` };
    const closure = closures.find((c) => c.starts_on <= day && c.ends_on >= day);
    if (closure) return { off: true, label: `Fermeture : ${closure.label}` };

    return { off: isoWeekday(day) >= 6, label: null };
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** A JSON answer and, on a 422, the first error of each field. */
export interface JsonAnswer<T> {
    ok: boolean;
    status: number;
    data: T | null;
    errors: Record<string, string>;
    message: string | null;
}

/** GET, POST or DELETE JSON to the application (session cookie and XSRF token). */
export async function sendJson<T>(method: 'GET' | 'POST' | 'DELETE', url: string, body?: unknown): Promise<JsonAnswer<T>> {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const json = await response.json().catch(() => null);
    const errors: Record<string, string> = {};
    if (response.status === 422 && json?.errors) {
        Object.entries(json.errors as Record<string, string[]>).forEach(([key, messages]) => (errors[key] = messages[0]));
    }

    return {
        ok: response.ok,
        status: response.status,
        data: response.ok ? (json as T) : null,
        errors,
        message: response.ok ? null : (json?.message ?? 'Une erreur est survenue.'),
    };
}
