/** Shapes and helpers of the invoicing pages (module Finance, 0.9). */

export type InvoiceKind = 'invoice' | 'credit_note';
export type InvoiceStatus = 'draft' | 'validated' | 'sent';

/** A row of the invoices list or of the dossier's block (Finance\Invoices\InvoiceRows). */
export interface InvoiceRow {
    id: string;
    title: string;
    kind: InvoiceKind;
    kind_label: string;
    number: string | null;
    status: InvoiceStatus;
    status_label: string;
    issued_on: string | null;
    created_on: string | null;
    recipient: string;
    recipient_role: 'contractor' | 'funder';
    dossier: { id: string; number: string; url: string };
    total_ht_cents: number;
    total_ttc_cents: number;
    /** Issued invoices only (0.9.1); null for a draft or a credit note. */
    paid_cents: number | null;
    remaining_cents: number | null;
    payment_status: 'unpaid' | 'partial' | 'paid' | null;
    payment_status_label: string | null;
    overdue: boolean;
    url: string;
}

/** Minutes as « 12 h 30 », « 9 h ». */
export function formatMinutes(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined) return '-';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return m === 0 ? `${h} h` : `${h} h ${String(m).padStart(2, '0')}`;
}

/** « 0.4286 » as « 42,86 % ». */
export function formatRatio(ratio: string): string {
    const [units, decimals = ''] = ratio.split('.');
    const basisPoints = Number(units) * 10000 + Number(decimals.padEnd(4, '0').slice(0, 4));

    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(basisPoints / 100)} %`;
}

export function invoiceStatusVariant(status: InvoiceStatus): 'default' | 'secondary' | 'outline' {
    return status === 'draft' ? 'outline' : status === 'sent' ? 'default' : 'secondary';
}
