const eurosWhole = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', minimumFractionDigits: 0, maximumFractionDigits: 0 });
const eurosCents = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: 2 });
const typed = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const percent = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });

/** Cents as « 12 345 € » (the table: no useless decimals, but a rounded figure is never shown for amounts with cents). */
export function formatCentsWhole(cents: number | null | undefined): string {
    if (cents === null || cents === undefined) return '-';

    return cents % 100 === 0 ? eurosWhole.format(cents / 100) : eurosCents.format(cents / 100);
}

/** Cents as « 1 250,50 € » (the detail). */
export function formatCents(cents: number): string {
    return eurosCents.format(cents / 100);
}

/** Cents as typed in a field (« 10 000,00 »); empty when there is none. */
export function centsInput(cents: number | null | undefined): string {
    return cents === null || cents === undefined ? '' : typed.format(cents / 100);
}

/** A rate in % (one decimal at most): « 33,3 % ». */
export function formatRate(rate: number): string {
    return `${percent.format(rate)} %`;
}

export interface RevenuePeriod {
    type: 'month' | 'quarter' | 'year';
    key: string;
    label: string;
    previous_key: string;
    next_key: string;
}

export interface RevenueRow {
    das_id: string;
    das_label: string;
    quotes_cents: number;
    opportunities_cents: number;
    total_cents: number;
    target_cents: number | null;
    /** Percent of the target reached (one decimal), null without target. */
    rate: number | null;
}
