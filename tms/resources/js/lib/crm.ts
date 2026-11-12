export interface Option {
    value: string;
    label: string;
}

export interface StatusValue {
    value: string;
    label: string;
}

const date = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeZone: 'Europe/Paris' });
const dateTime = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Europe/Paris' });

export function formatDate(value: string | null | undefined): string {
    return value ? date.format(new Date(value)) : '—';
}

export function formatDateTime(value: string | null | undefined): string {
    return value ? dateTime.format(new Date(value)) : '—';
}

export function formatSiret(value: string | null | undefined): string {
    if (!value) return '—';

    return value.replace(/^(\d{3})(\d{3})(\d{3})(\d{5})$/, '$1 $2 $3 $4');
}

/** Badge variant of an entreprise status (prospect | client | inactif). */
export function statusVariant(status: string): 'default' | 'secondary' | 'outline' {
    return status === 'client' ? 'default' : status === 'prospect' ? 'secondary' : 'outline';
}

/** Stored E.164 French number (+33612345678) shown as 06 12 34 56 78; anything else as stored. */
export function formatPhone(value: string | null | undefined): string {
    if (!value) return '—';
    const match = /^\+33([1-9])(\d{8})$/.exec(value);
    if (!match) return value;
    const digits = `0${match[1]}${match[2]}`;

    return digits.replace(/(\d{2})(?=\d)/g, '$1 ');
}

export interface Paginated<T> {
    data: T[];
    total: number;
    from: number | null;
    to: number | null;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

/** Today's date (Europe/Paris) as YYYY-MM-DD, for date inputs. */
export function todayIso(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris' }).format(new Date());
}

/** First error among the per-item keys of an array field (`das_ids.0`, `das_ids.1`…). */
export function itemError(errors: Record<string, string | undefined>, field: string): string | undefined {
    return Object.entries(errors).find(([key, message]) => key.startsWith(`${field}.`) && message)?.[1];
}
