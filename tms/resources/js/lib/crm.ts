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
