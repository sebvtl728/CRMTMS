/** A session as the list, the calendar and the session page receive it (SessionRows). */
export interface SessionRow {
    id: string;
    number: string;
    /** The planning colour of the session (« #2563eb »). */
    color: string | null;
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
    location_kind: 'address' | 'company' | 'client' | 'remote';
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
    if (row.location_kind === 'address') return row.location_text ?? '-';

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
            ...(body === undefined || body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
        },
        body: body === undefined ? undefined : body instanceof FormData ? body : JSON.stringify(body),
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

/** Hours of a training day: the end of a session is estimated from its hours (as Training::HOURS_PER_DAY). */
export const HOURS_PER_DAY = 7;

/** « 21 », « 7,5 », « 21.00 » or « 10 h 30 » as hours; NaN otherwise. */
export function parseHours(value: string | null | undefined): number {
    const text = (value ?? '').trim();
    const hm = text.match(/^(\d+)\s*h\s*(\d{1,2})?$/i);
    if (hm) return Number(hm[1]) + (hm[2] ? Number(hm[2]) / 60 : 0);

    return Number.parseFloat(text.replace(/\s/g, '').replace(',', '.'));
}

/** French public holidays of a year (as Sessions\Planning\PublicHolidays), « YYYY-MM-DD ». */
function holidays(year: number): Set<string> {
    // Easter Sunday (anonymous Gregorian algorithm).
    const a = year % 19;
    const b = Math.floor(year / 100);
    const c = year % 100;
    const d = Math.floor(b / 4);
    const e = b % 4;
    const f = Math.floor((b + 8) / 25);
    const g = Math.floor((b - f + 1) / 3);
    const h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4);
    const k = c % 4;
    const l = (32 + 2 * e + 2 * i - h - k) % 7;
    const m = Math.floor((a + 11 * h + 22 * l) / 451);
    const month = Math.floor((h + l - 7 * m + 114) / 31);
    const day = ((h + l - 7 * m + 114) % 31) + 1;
    const easter = Date.UTC(year, month - 1, day);
    const shift = (days: number) => new Date(easter + days * 86_400_000).toISOString().slice(0, 10);

    return new Set([
        `${year}-01-01`,
        shift(1),
        `${year}-05-01`,
        `${year}-05-08`,
        shift(39),
        shift(50),
        `${year}-07-14`,
        `${year}-08-15`,
        `${year}-11-01`,
        `${year}-11-11`,
        `${year}-12-25`,
    ]);
}

/**
 * The approximate last day of a session starting on $start for $hours: one
 * day of HOURS_PER_DAY hours per working day (Monday to Friday, French public
 * holidays left out). Null when the start or the hours are unusable.
 */
export function estimateEndDate(start: string, hours: number): string | null {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(start) || !Number.isFinite(hours) || hours <= 0 || hours > 5000) return null;
    let days = Math.ceil(hours / HOURS_PER_DAY);
    let date = new Date(`${start}T00:00:00Z`);
    const cache = new Map<number, Set<string>>();
    const off = (d: Date) => {
        const year = d.getUTCFullYear();
        if (!cache.has(year)) cache.set(year, holidays(year));
        return d.getUTCDay() === 0 || d.getUTCDay() === 6 || cache.get(year)!.has(d.toISOString().slice(0, 10));
    };
    while (off(date)) date = new Date(date.getTime() + 86_400_000);
    days -= 1;
    while (days > 0) {
        date = new Date(date.getTime() + 86_400_000);
        if (!off(date)) days -= 1;
    }

    return date.toISOString().slice(0, 10);
}
