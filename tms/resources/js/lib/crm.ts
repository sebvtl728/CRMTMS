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

/** SIRET as typed (spaces and dots allowed) reduced to its digits. */
export function cleanSiret(value: string): string {
    return value.replace(/[\s.]/g, '');
}

/** 14 digits passing the Luhn check (same rule as the server). */
export function isValidSiret(value: string): boolean {
    const siret = cleanSiret(value);
    if (!/^\d{14}$/.test(siret)) return false;

    let sum = 0;
    [...siret].reverse().forEach((char, i) => {
        let digit = Number(char);
        if (i % 2 === 1) {
            digit *= 2;
            if (digit > 9) digit -= 9;
        }
        sum += digit;
    });

    return sum % 10 === 0;
}

const euros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: 2 });
const decimal = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Stored decimal (« 12500.00 ») shown as « 12 500,00 € ». */
export function formatAmount(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';

    return euros.format(Number(value));
}

/** Stored decimal (« 12500.00 ») as typed in a form (« 12 500,00 »). */
export function amountInput(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '';

    return decimal.format(Number(value));
}

/** A date-only value (YYYY-MM-DD) as « 15/12/2026 », without time zone shift. */
export function formatDay(value: string | null | undefined): string {
    if (!value) return '—';
    const [y, m, d] = value.split('-');

    return `${d}/${m}/${y}`;
}
