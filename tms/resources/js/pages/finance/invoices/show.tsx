import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDay, type Option } from '@/lib/crm';
import { formatMinutes, invoiceStatusVariant, type InvoiceRow } from '@/lib/finance';
import { formatCents } from '@/lib/revenue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

interface InvoiceLine {
    id: string;
    kind: 'computed' | 'free';
    component: 'training' | 'dedit' | 'absences' | 'mention' | null;
    label: string;
    description: string | null;
    quantity: string;
    unit: string;
    unit_price: string;
    unit_price_cents: number;
    vat_rate: string;
    total_ht_cents: number;
    computed_cents: number | null;
    planned_minutes: number | null;
    realised_minutes: number | null;
}

interface InvoicePage extends Omit<InvoiceRow, 'recipient'> {
    recipient: { label: string; role: 'contractor' | 'funder'; type: string };
    period: { from: string | null; to: string | null };
    cut_off: string | null;
    rhythm_label: string;
    total_vat_cents: number;
    vat_mention: string | null;
    notes: string | null;
    due_on: string | null;
    corrected: { id: string; title: string; url: string } | null;
    lines: InvoiceLine[];
}

interface ShowProps {
    invoice: InvoicePage;
    options: { units: Option[]; vat_rates: Option[]; vat_exempt: boolean };
    can: { update: boolean };
    status?: string;
}

type LineForm = {
    id?: string;
    kind: 'computed' | 'free';
    component: InvoiceLine['component'];
    label: string;
    description: string;
    quantity: string;
    unit: string;
    unit_price: string;
    vat_rate: string;
};

const COMPONENT_LABELS: Record<string, string> = {
    training: 'Formation',
    dedit: 'Dédit',
    absences: 'Absences',
    mention: 'Mention',
};

function LinesTable({ invoice }: { invoice: InvoicePage }) {
    return (
        <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">Désignation</th>
                        <th className="px-3 py-2 text-right font-medium">Quantité</th>
                        <th className="px-3 py-2 text-right font-medium">Prix unitaire HT</th>
                        <th className="px-3 py-2 text-right font-medium">TVA</th>
                        <th className="px-3 py-2 text-right font-medium">Total HT</th>
                    </tr>
                </thead>
                <tbody>
                    {invoice.lines.map((line) => (
                        <tr key={line.id} className="border-t align-top">
                            <td className="px-3 py-2">
                                {line.label}
                                {line.description && <span className="text-muted-foreground block text-xs">{line.description}</span>}
                            </td>
                            <td className="px-3 py-2 text-right whitespace-nowrap">
                                {line.quantity} {line.unit}
                            </td>
                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">{formatCents(line.unit_price_cents)}</td>
                            <td className="px-3 py-2 text-right whitespace-nowrap">{line.vat_rate.replace('.', ',')} %</td>
                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">{formatCents(line.total_ht_cents)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** The lines of a draft: computed lines kept (label and amount editable), free lines added and removed. */
function DraftEditor({ invoice, options }: { invoice: InvoicePage; options: ShowProps['options'] }) {
    const { data, setData, put, processing, errors, isDirty } = useForm<{ notes: string; lines: LineForm[] }>({
        notes: invoice.notes ?? '',
        lines: invoice.lines.map((l) => ({
            id: l.id,
            kind: l.kind,
            component: l.component,
            label: l.label,
            description: l.description ?? '',
            quantity: l.quantity,
            unit: l.unit,
            unit_price: l.unit_price,
            vat_rate: l.vat_rate,
        })),
    });
    const err = errors as Record<string, string | undefined>;
    const byId = Object.fromEntries(invoice.lines.map((l) => [l.id, l]));

    const setLine = (index: number, key: keyof LineForm, value: string) =>
        setData(
            'lines',
            data.lines.map((l, i) => (i === index ? { ...l, [key]: value } : l)),
        );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('finance.invoices.update', invoice.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <ul className="grid gap-3">
                {data.lines.map((line, index) => {
                    const stored = line.id ? byId[line.id] : undefined;
                    const vatLocked = options.vat_exempt || line.component === 'dedit';

                    return (
                        <li key={line.id ?? `new-${index}`} className="grid gap-2 rounded-xl border p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Badge variant={line.kind === 'computed' ? 'secondary' : 'outline'}>
                                    {line.kind === 'computed' ? `Calculée · ${COMPONENT_LABELS[line.component ?? ''] ?? ''}` : 'Ligne libre'}
                                </Badge>
                                {line.kind === 'free' && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Retirer la ligne ${index + 1}`}
                                        onClick={() =>
                                            setData(
                                                'lines',
                                                data.lines.filter((_, j) => j !== index),
                                            )
                                        }
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                )}
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor={`line-${index}-label`}>Libellé</Label>
                                <Input id={`line-${index}-label`} value={line.label} onChange={(e) => setLine(index, 'label', e.target.value)} />
                                <InputError message={err[`lines.${index}.label`]} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor={`line-${index}-description`}>Description</Label>
                                <Input
                                    id={`line-${index}-description`}
                                    value={line.description}
                                    onChange={(e) => setLine(index, 'description', e.target.value)}
                                />
                                <InputError message={err[`lines.${index}.description`]} />
                            </div>
                            <div className="grid gap-2 sm:grid-cols-4">
                                <div className="grid gap-1">
                                    <Label htmlFor={`line-${index}-quantity`}>Quantité</Label>
                                    <Input
                                        id={`line-${index}-quantity`}
                                        inputMode="decimal"
                                        value={line.quantity}
                                        onChange={(e) => setLine(index, 'quantity', e.target.value)}
                                    />
                                    <InputError message={err[`lines.${index}.quantity`]} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`line-${index}-unit`}>Unité</Label>
                                    <select
                                        id={`line-${index}-unit`}
                                        className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                        value={line.unit}
                                        onChange={(e) => setLine(index, 'unit', e.target.value)}
                                    >
                                        {options.units.map((u) => (
                                            <option key={u.value} value={u.value}>
                                                {u.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`line-${index}-unit_price`}>Prix unitaire HT (€)</Label>
                                    <Input
                                        id={`line-${index}-unit_price`}
                                        inputMode="decimal"
                                        value={line.unit_price}
                                        onChange={(e) => setLine(index, 'unit_price', e.target.value)}
                                    />
                                    <InputError message={err[`lines.${index}.unit_price`]} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`line-${index}-vat_rate`}>TVA</Label>
                                    <select
                                        id={`line-${index}-vat_rate`}
                                        className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                        value={vatLocked ? '0.00' : line.vat_rate}
                                        disabled={vatLocked}
                                        onChange={(e) => setLine(index, 'vat_rate', e.target.value)}
                                    >
                                        {options.vat_rates.map((r) => (
                                            <option key={r.value} value={r.value}>
                                                {r.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={err[`lines.${index}.vat_rate`]} />
                                </div>
                            </div>
                            {stored && (
                                <p className="text-muted-foreground text-xs">
                                    Total enregistré : {formatCents(stored.total_ht_cents)}
                                    {stored.computed_cents !== null && stored.computed_cents !== stored.total_ht_cents && (
                                        <> · montant calculé : {formatCents(stored.computed_cents)}</>
                                    )}
                                    {stored.planned_minutes !== null && (
                                        <>
                                            {' '}
                                            · {formatMinutes(stored.realised_minutes)} réalisées / {formatMinutes(stored.planned_minutes)} prévues
                                        </>
                                    )}
                                </p>
                            )}
                        </li>
                    );
                })}
            </ul>
            <InputError message={err.lines} />

            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        setData('lines', [
                            ...data.lines,
                            {
                                kind: 'free',
                                component: null,
                                label: '',
                                description: '',
                                quantity: '1',
                                unit: 'forfait',
                                unit_price: '',
                                vat_rate: options.vat_exempt ? '0.00' : '20.00',
                            },
                        ])
                    }
                >
                    <Plus className="h-4 w-4" />
                    Ajouter une ligne libre
                </Button>
            </div>

            <div className="grid gap-1">
                <Label htmlFor="notes">Notes internes</Label>
                <textarea
                    id="notes"
                    className="border-input bg-background min-h-20 rounded-md border px-3 py-2 text-sm"
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                />
                <InputError message={err.notes} />
            </div>

            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={processing || !isDirty}>
                    Enregistrer le brouillon
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    onClick={() => {
                        if (window.confirm('Supprimer ce brouillon ?')) router.delete(route('finance.invoices.destroy', invoice.id));
                    }}
                >
                    Supprimer le brouillon
                </Button>
            </div>
        </form>
    );
}

export default function InvoiceShow({ invoice, options, can, status }: ShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Factures', href: '/finance/invoices' },
        { title: invoice.title, href: invoice.url },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={invoice.title} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title={invoice.title}
                        description={`${invoice.recipient.role === 'contractor' ? 'Contractant' : 'Financeur'} : ${invoice.recipient.label}`}
                    />
                    <Badge variant={invoiceStatusVariant(invoice.status)}>{invoice.status_label}</Badge>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <dl className="grid gap-x-6 gap-y-2 rounded-xl border p-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt className="text-muted-foreground text-xs">Dossier</dt>
                        <dd>
                            <Link href={invoice.dossier.url} className="underline">
                                {invoice.dossier.number}
                            </Link>
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-xs">Période</dt>
                        <dd>{invoice.period.from ? `du ${formatDay(invoice.period.from)} au ${formatDay(invoice.period.to)}` : '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground text-xs">Rythme</dt>
                        <dd>{invoice.rhythm_label}</dd>
                    </div>
                    {invoice.issued_on && (
                        <div>
                            <dt className="text-muted-foreground text-xs">Date d'émission</dt>
                            <dd>{formatDay(invoice.issued_on)}</dd>
                        </div>
                    )}
                    {invoice.due_on && (
                        <div>
                            <dt className="text-muted-foreground text-xs">Échéance</dt>
                            <dd>{formatDay(invoice.due_on)}</dd>
                        </div>
                    )}
                    {invoice.corrected && (
                        <div>
                            <dt className="text-muted-foreground text-xs">Corrige</dt>
                            <dd>
                                <Link href={invoice.corrected.url} className="underline">
                                    {invoice.corrected.title}
                                </Link>
                            </dd>
                        </div>
                    )}
                </dl>

                {can.update ? <DraftEditor invoice={invoice} options={options} /> : <LinesTable invoice={invoice} />}

                <dl className="ml-auto grid w-full max-w-xs gap-1 text-sm">
                    <div className="flex justify-between">
                        <dt>Total HT</dt>
                        <dd className="tabular-nums">{formatCents(invoice.total_ht_cents)}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>TVA</dt>
                        <dd className="tabular-nums">{formatCents(invoice.total_vat_cents)}</dd>
                    </div>
                    <div className="flex justify-between font-semibold">
                        <dt>Total TTC</dt>
                        <dd className="tabular-nums">{formatCents(invoice.total_ttc_cents)}</dd>
                    </div>
                </dl>
                {invoice.vat_mention && <p className="text-muted-foreground text-xs whitespace-pre-line">{invoice.vat_mention}</p>}
                {!can.update && invoice.notes && (
                    <div className="text-sm">
                        <p className="text-muted-foreground text-xs">Notes internes</p>
                        <p className="whitespace-pre-line">{invoice.notes}</p>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
