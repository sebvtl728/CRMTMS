import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { amountInput, type Option } from '@/lib/crm';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';

export interface QuoteTrainingOption extends Option {
    title: string;
    das_id: string;
    /** Price HT for the target of the opportunity (« 1200.00 »), null when missing. */
    unit_price: string | null;
    is_active: boolean;
}

// A type, not an interface: useForm needs an index-compatible shape.
export type QuoteLineInput = {
    training_id: string;
    label: string;
    description: string;
    quantity: string;
    unit: string;
    unit_price: string;
    discount_percent: string;
    vat_rate: string;
};

const FREE = 'free';

/** A stored decimal (« 3.00 », « 7.50 ») as typed (« 3 », « 7,5 »). */
export function decimalInput(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '';

    return value.includes('.') ? value.replace(/\.?0+$/, '').replace('.', ',') : value;
}

/** A stored line as the form edits it. */
export function lineInput(line: {
    training_id: string | null;
    label: string;
    description: string | null;
    quantity: string;
    unit: string;
    unit_price: string | null;
    discount_percent: string;
    vat_rate: string;
}): QuoteLineInput {
    return {
        training_id: line.training_id ?? '',
        label: line.label,
        description: line.description ?? '',
        quantity: decimalInput(line.quantity),
        unit: line.unit,
        unit_price: amountInput(line.unit_price),
        discount_percent: decimalInput(line.discount_percent),
        vat_rate: line.vat_rate,
    };
}

/**
 * A French typed decimal (« 12 500,00 », « 2,5 ») in hundredths, null when it
 * is not a positive number with two decimals at most (the server refuses it).
 */
function hundredths(value: string): bigint | null {
    const clean = value.replace(/[\s\u00A0\u202F€%]/g, '').replace(',', '.');
    const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(clean === '' ? '0' : clean);

    return match ? BigInt(match[1]) * 100n + BigInt((match[2] ?? '0').padEnd(2, '0')) : null;
}

/** Integer division rounded half up (non-negative operands). */
function divideHalfUp(numerator: bigint, divisor: bigint): bigint {
    return (numerator * 2n + divisor) / (divisor * 2n);
}

export interface TotalsPreview {
    lines: (bigint | null)[];
    vatByRate: { rate: string; base: bigint; vat: bigint }[];
    ht: bigint;
    vat: bigint;
    ttc: bigint;
}

/**
 * Preview of QuoteTotals (same integer rules: line HT rounded half up, VAT
 * per rate on the summed base). The server recomputes on save.
 */
export function previewTotals(lines: QuoteLineInput[], vatExempt: boolean): TotalsPreview {
    const bases = new Map<string, bigint>();
    let ht = 0n;
    const totals = lines.map((line) => {
        const q = hundredths(line.quantity);
        const p = hundredths(line.unit_price);
        const d = hundredths(line.discount_percent);
        if (q === null || p === null || d === null || d > 10000n) return null;
        const total = divideHalfUp(q * p * (10000n - d), 1_000_000n);
        const rate = vatExempt ? '0.00' : line.vat_rate;
        bases.set(rate, (bases.get(rate) ?? 0n) + total);
        ht += total;

        return total;
    });

    const vatByRate = [...bases.entries()]
        .sort(([a], [b]) => Number(b) - Number(a))
        .map(([rate, base]) => ({ rate, base, vat: divideHalfUp(base * (hundredths(rate) ?? 0n), 10000n) }));
    const vat = vatByRate.reduce((sum, r) => sum + r.vat, 0n);

    return { lines: totals, vatByRate, ht, vat, ttc: ht + vat };
}

/** Cents as « 12 500,00 € ». */
export function formatCentsBig(cents: bigint | null): string {
    if (cents === null) return '-';
    const euros = (cents / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u00A0');

    return `${euros},${(cents % 100n).toString().padStart(2, '0')} €`;
}

/** « 5.50 » → « 5,5 % ». */
export function rateLabel(rate: string): string {
    return `${decimalInput(rate) || '0'} %`;
}

export function emptyLine(vatRate: string): QuoteLineInput {
    return {
        training_id: '',
        label: '',
        description: '',
        quantity: '1',
        unit: 'stagiaire',
        unit_price: '',
        discount_percent: '0',
        vat_rate: vatRate,
    };
}

/**
 * Lines of the quote form: a training of the catalogue (or a free line),
 * label, quantity, unit, unit price HT, discount, VAT rate (hidden for an
 * exempt company: always 0 %), with the line totals previewed.
 */
export function QuoteLinesField({
    lines,
    options,
    vatExempt,
    errors,
    onChange,
}: {
    lines: QuoteLineInput[];
    options: { training: QuoteTrainingOption[]; units: Option[]; vat_rates: Option[] };
    vatExempt: boolean;
    errors: Record<string, string | undefined>;
    onChange: (lines: QuoteLineInput[]) => void;
}) {
    const preview = previewTotals(lines, vatExempt);
    const set = (index: number, changes: Partial<QuoteLineInput>) => onChange(lines.map((l, i) => (i === index ? { ...l, ...changes } : l)));
    const move = (index: number, by: number) => {
        const next = [...lines];
        const [line] = next.splice(index, 1);
        next.splice(index + by, 0, line);
        onChange(next);
    };
    const pickTraining = (index: number, value: string) => {
        if (value === FREE) {
            set(index, { training_id: '' });
            return;
        }
        const training = options.training.find((t) => t.value === value);
        set(index, {
            training_id: value,
            label: training?.title ?? lines[index].label,
            unit_price: training?.unit_price ? amountInput(training.unit_price) : lines[index].unit_price,
        });
    };
    const error = (index: number, field: keyof QuoteLineInput) => errors[`lines.${index}.${field}`];

    return (
        <fieldset className="grid gap-4 rounded-xl border p-4">
            <legend className="px-2 text-sm font-semibold">Lignes</legend>
            <InputError message={errors.lines} />

            {lines.map((line, index) => {
                const id = (field: string) => `lines-${index}-${field}`;
                const trainings = options.training.filter((t) => t.is_active || t.value === line.training_id);

                return (
                    <div key={index} className="grid gap-3 rounded-lg border p-3 md:grid-cols-6" aria-label={`Ligne ${index + 1}`} role="group">
                        <div className="grid gap-2 md:col-span-3">
                            <Label htmlFor={id('training')}>Formation du catalogue</Label>
                            <Select value={line.training_id || FREE} onValueChange={(v) => pickTraining(index, v)}>
                                <SelectTrigger id={id('training')}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={FREE}>Ligne libre (frais, prestation…)</SelectItem>
                                    {trainings.map((t) => (
                                        <SelectItem key={t.value} value={t.value}>
                                            {t.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={error(index, 'training_id')} />
                        </div>
                        <div className="grid gap-2 md:col-span-3">
                            <Label htmlFor={id('label')}>Libellé *</Label>
                            <Input id={id('label')} value={line.label} maxLength={300} onChange={(e) => set(index, { label: e.target.value })} />
                            <InputError message={error(index, 'label')} />
                        </div>
                        <div className="grid gap-2 md:col-span-6">
                            <Label htmlFor={id('description')}>Description</Label>
                            <textarea
                                id={id('description')}
                                value={line.description}
                                maxLength={2000}
                                rows={2}
                                onChange={(e) => set(index, { description: e.target.value })}
                                className="border-input placeholder:text-muted-foreground focus-visible:ring-ring rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none"
                            />
                            <InputError message={error(index, 'description')} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={id('quantity')}>Quantité *</Label>
                            <Input
                                id={id('quantity')}
                                inputMode="decimal"
                                value={line.quantity}
                                onChange={(e) => set(index, { quantity: e.target.value })}
                            />
                            <InputError message={error(index, 'quantity')} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={id('unit')}>Unité</Label>
                            <Select value={line.unit} onValueChange={(v) => set(index, { unit: v })}>
                                <SelectTrigger id={id('unit')}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.units.map((u) => (
                                        <SelectItem key={u.value} value={u.value}>
                                            {u.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={error(index, 'unit')} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={id('unit_price')}>Prix unitaire HT (€) *</Label>
                            <Input
                                id={id('unit_price')}
                                inputMode="decimal"
                                value={line.unit_price}
                                placeholder="1 200,00"
                                onChange={(e) => set(index, { unit_price: e.target.value })}
                            />
                            <InputError message={error(index, 'unit_price')} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={id('discount_percent')}>Remise (%)</Label>
                            <Input
                                id={id('discount_percent')}
                                inputMode="decimal"
                                value={line.discount_percent}
                                onChange={(e) => set(index, { discount_percent: e.target.value })}
                            />
                            <InputError message={error(index, 'discount_percent')} />
                        </div>
                        {vatExempt ? (
                            <div className="grid gap-2">
                                <span className="text-sm font-medium">TVA</span>
                                <p className="text-sm">0 % (exonérée)</p>
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor={id('vat_rate')}>TVA</Label>
                                <Select value={line.vat_rate} onValueChange={(v) => set(index, { vat_rate: v })}>
                                    <SelectTrigger id={id('vat_rate')}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.vat_rates.map((r) => (
                                            <SelectItem key={r.value} value={r.value}>
                                                {r.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={error(index, 'vat_rate')} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <span className="text-sm font-medium">Total HT</span>
                            <p className="text-sm tabular-nums">{formatCentsBig(preview.lines[index])}</p>
                        </div>
                        <div className="flex flex-wrap gap-1 md:col-span-6">
                            <Button type="button" variant="ghost" size="sm" disabled={index === 0} onClick={() => move(index, -1)}>
                                <ArrowUp className="h-4 w-4" />
                                Monter
                            </Button>
                            <Button type="button" variant="ghost" size="sm" disabled={index === lines.length - 1} onClick={() => move(index, 1)}>
                                <ArrowDown className="h-4 w-4" />
                                Descendre
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-destructive"
                                onClick={() => onChange(lines.filter((_, i) => i !== index))}
                            >
                                <Trash2 className="h-4 w-4" />
                                Retirer la ligne
                            </Button>
                        </div>
                    </div>
                );
            })}

            <div>
                <Button type="button" variant="outline" onClick={() => onChange([...lines, emptyLine(vatExempt ? '0.00' : '20.00')])}>
                    <Plus className="h-4 w-4" />
                    Ajouter une ligne
                </Button>
            </div>

            <dl className="ml-auto grid w-full max-w-sm gap-1 text-sm">
                <div className="flex justify-between">
                    <dt>Total HT</dt>
                    <dd className="tabular-nums">{formatCentsBig(preview.ht)}</dd>
                </div>
                {preview.vatByRate.map((r) => (
                    <div key={r.rate} className="text-muted-foreground flex justify-between">
                        <dt>
                            TVA {rateLabel(r.rate)} sur {formatCentsBig(r.base)}
                        </dt>
                        <dd className="tabular-nums">{formatCentsBig(r.vat)}</dd>
                    </div>
                ))}
                <div className="flex justify-between font-semibold">
                    <dt>Total TTC</dt>
                    <dd className="tabular-nums">{formatCentsBig(preview.ttc)}</dd>
                </div>
                <p className="text-muted-foreground text-xs">Montants recalculés à l'enregistrement.</p>
            </dl>
        </fieldset>
    );
}
