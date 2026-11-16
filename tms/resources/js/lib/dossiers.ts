/** A dossier as the list and the dossier page receive it (DossierRows). */
export interface DossierRow {
    id: string;
    number: string;
    account: { type: 'organisation' | 'person'; id: string; label: string; trashed: boolean; can_open: boolean };
    training_titles: string[];
    das: string | null;
    price_cents: number;
    /** « 4 031,92 € » (server, exact to the cent). */
    price: string;
    /** « Reste à financer » (cents; negative: fundings above the price) and its French cell. */
    remaining_cents: number;
    remaining: string;
    responsible: string | null;
    /** Effective state of the Paris day. */
    status: 'created' | 'convention_sent' | 'convention_signed' | 'convocations_sent' | 'running' | 'done' | 'cancelled' | 'invoiced' | 'settled';
    status_label: string;
    created_on: string | null;
    trashed: boolean;
}

export function dossierStatusVariant(status: DossierRow['status']): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (status) {
        case 'running':
            return 'default';
        case 'done':
            return 'secondary';
        case 'cancelled':
            return 'destructive';
        default:
            return 'outline';
    }
}

/** Route of the CRM fiche of a contractant or a contact. */
export function ficheRoute(type: 'organisation' | 'person', id: string): string {
    return type === 'organisation' ? route('crm.organisations.show', id) : route('crm.people.show', id);
}

/** « 1 234,5 » → hundredths as a BigInt (null when not a number with two decimals at most). */
function hundredths(value: string): bigint | null {
    const clean = value.replace(/[\s\u00a0\u202f]/g, '').replace(',', '.');
    const match = /^(\d{1,10})(?:\.(\d{1,2}))?$/.exec(clean);
    if (!match) return null;

    return BigInt(match[1]) * 100n + BigInt((match[2] ?? '').padEnd(2, '0'));
}

/**
 * Preview of a line total in cents, the server's rounding (half up) in
 * integers: round(q × p × (10000 − d) / 1 000 000). The server computes the
 * stored amount.
 */
export function lineTotalCents(quantity: string, unitPrice: string, discount: string): bigint | null {
    const q = hundredths(quantity);
    const p = hundredths(unitPrice);
    const d = discount.trim() === '' ? 0n : hundredths(discount);
    if (q === null || p === null || d === null || d > 10000n) return null;
    const product = q * p * (10000n - d);

    return (product + 500000n) / 1000000n;
}

/** 403192n → « 4 031,92 € ». */
export function displayCents(cents: bigint): string {
    const euros = (cents / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

    return `${euros},${(cents % 100n).toString().padStart(2, '0')} €`;
}
