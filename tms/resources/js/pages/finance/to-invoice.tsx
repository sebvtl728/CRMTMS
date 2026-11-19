import { FilterSelect } from '@/components/crm/filter-select';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { formatMinutes, formatRatio, type InvoiceKind } from '@/lib/finance';
import { formatCents } from '@/lib/revenue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';

interface Component {
    share: number;
    invoiced: number;
    due: number;
}

interface DueLine {
    component: 'training' | 'dedit' | 'absences' | 'mention';
    label: string;
    description: string | null;
    total_ht_cents: number;
}

interface DueRow {
    key: string;
    dossier: { id: string; number: string; url: string; contractor: string };
    recipient_key: string;
    recipient: { role: 'contractor' | 'funder'; label: string };
    rhythm: string;
    rhythm_label: string;
    cut_off: string;
    period: { from: string; to: string; label: string };
    kind: InvoiceKind;
    total_cents: number;
    components: Record<'training' | 'dedit' | 'absences', Component>;
    lines: DueLine[];
    split: { label: string; role: 'contractor' | 'funder'; training_cents: number; external: boolean }[];
    detail: {
        line_id: string;
        line_label: string;
        trainee: string | null;
        planned: number | null;
        realised: number | null;
        ratio: string;
        amount_cents: number;
        dedit_cents: number;
        absences_cents: number;
    }[];
    modified_cents: number;
    draft: { id: string; url: string } | null;
    preparable: boolean;
    blocked_reason: string | null;
    unsettled_cents: number;
}

interface SoonRow {
    key: string;
    dossier: { id: string; number: string; url: string; contractor: string };
    rhythm_label: string;
    reason: string;
}

interface Filters {
    company: string | null;
    das: string | null;
    responsible: string | null;
    month: string | null;
}

interface ToInvoiceProps {
    rows: DueRow[];
    soon: SoonRow[];
    filters: Filters;
    options: { company: Option[]; das: Option[]; responsible: Option[] };
    can: { manage: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'À facturer', href: '/finance/to-invoice' }];

const COMPONENTS: { key: 'training' | 'dedit' | 'absences'; label: string }[] = [
    { key: 'training', label: 'Formation' },
    { key: 'dedit', label: 'Dédit' },
    { key: 'absences', label: 'Absences non justifiées' },
];

function query(filters: Filters): Record<string, string> {
    const q: Record<string, string> = {};
    (Object.keys(filters) as (keyof Filters)[]).forEach((k) => {
        if (filters[k]) q[k] = String(filters[k]);
    });

    return q;
}

/** How the amount due was reached: hours, rates, the split between recipients, what was already invoiced. */
function Detail({ row }: { row: DueRow }) {
    return (
        <div className="grid gap-3 text-sm">
            <table className="w-full text-xs">
                <thead className="text-muted-foreground text-left">
                    <tr>
                        <th className="py-1 pr-2 font-medium">Ligne</th>
                        <th className="py-1 pr-2 font-medium">Stagiaire</th>
                        <th className="py-1 pr-2 text-right font-medium">Prévues</th>
                        <th className="py-1 pr-2 text-right font-medium">Réalisées</th>
                        <th className="py-1 pr-2 text-right font-medium">Taux</th>
                        <th className="py-1 text-right font-medium">Facturable</th>
                    </tr>
                </thead>
                <tbody>
                    {row.detail.map((d, i) => (
                        <tr key={i} className="border-t">
                            <td className="py-1 pr-2">{d.line_label}</td>
                            <td className="py-1 pr-2">{d.trainee ?? '-'}</td>
                            <td className="py-1 pr-2 text-right tabular-nums">{formatMinutes(d.planned)}</td>
                            <td className="py-1 pr-2 text-right tabular-nums">{formatMinutes(d.realised)}</td>
                            <td className="py-1 pr-2 text-right tabular-nums">{formatRatio(d.ratio)}</td>
                            <td className="py-1 text-right tabular-nums">{formatCents(d.amount_cents)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <div>
                <p className="text-muted-foreground text-xs font-medium">Partage de la formation</p>
                <ul className="text-xs">
                    {row.split.map((s, i) => (
                        <li key={i}>
                            {s.role === 'contractor' ? 'Contractant' : 'Financeur'} {s.label} : {formatCents(s.training_cents)}
                            {s.external && ' (facturé hors du TMS)'}
                        </li>
                    ))}
                </ul>
            </div>

            <table className="w-full max-w-md text-xs">
                <thead className="text-muted-foreground text-left">
                    <tr>
                        <th className="py-1 pr-2 font-medium">{row.recipient.label}</th>
                        <th className="py-1 pr-2 text-right font-medium">Part cumulée</th>
                        <th className="py-1 pr-2 text-right font-medium">Déjà facturé</th>
                        <th className="py-1 text-right font-medium">Dû</th>
                    </tr>
                </thead>
                <tbody>
                    {COMPONENTS.filter((c) => row.components[c.key].share !== 0 || row.components[c.key].invoiced !== 0).map((c) => (
                        <tr key={c.key} className="border-t">
                            <td className="py-1 pr-2">{c.label}</td>
                            <td className="py-1 pr-2 text-right tabular-nums">{formatCents(row.components[c.key].share)}</td>
                            <td className="py-1 pr-2 text-right tabular-nums">{formatCents(row.components[c.key].invoiced)}</td>
                            <td className="py-1 text-right tabular-nums">{formatCents(row.components[c.key].due)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {row.modified_cents !== 0 && (
                <p className="text-xs">
                    Des lignes calculées ont été modifiées sur les factures validées : écart de {formatCents(row.modified_cents)}.
                </p>
            )}

            <div>
                <p className="text-muted-foreground text-xs font-medium">Lignes proposées</p>
                <ul className="text-xs">
                    {row.lines.map((l, i) => (
                        <li key={i}>
                            {l.label}
                            {l.component !== 'mention' && ` : ${formatCents(l.total_ht_cents)}`}
                            {l.description && <span className="text-muted-foreground block">{l.description}</span>}
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

export default function ToInvoice({ rows, soon, filters, options, can, status }: ToInvoiceProps) {
    const [selected, setSelected] = useState<string[]>([]);
    const [open, setOpen] = useState<string[]>([]);
    const [processing, setProcessing] = useState(false);
    const selectable = rows.filter((r) => r.draft === null && r.preparable).map((r) => r.key);

    const visit = (changes: Partial<Filters>) =>
        router.get(route('finance.to-invoice.index'), query({ ...filters, ...changes }), { preserveState: true, replace: true });
    const toggle = (list: string[], key: string) => (list.includes(key) ? list.filter((k) => k !== key) : [...list, key]);
    const prepare = () =>
        router.post(
            route('finance.to-invoice.prepare'),
            { keys: selected },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setSelected([]);
                },
            },
        );

    const filtered = Boolean(filters.company || filters.das || filters.responsible || filters.month);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="À facturer" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title="À facturer"
                        description="Ce qui est dû par dossier et par destinataire : part cumulée au réalisé moins ce qui a déjà été facturé."
                    />
                    {can.manage && (
                        <Button type="button" onClick={prepare} disabled={processing || selected.length === 0}>
                            Préparer les brouillons{selected.length > 0 ? ` (${selected.length})` : ''}
                        </Button>
                    )}
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <section aria-label="Filtres" className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2 lg:grid-cols-5">
                    <FilterSelect label="Société" value={filters.company} options={options.company} onChange={(v) => visit({ company: v })} />
                    <FilterSelect label="DAS" value={filters.das} options={options.das} onChange={(v) => visit({ das: v })} />
                    <FilterSelect
                        label="Responsable"
                        value={filters.responsible}
                        options={options.responsible}
                        onChange={(v) => visit({ responsible: v })}
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="month" className="text-xs">
                            Période se terminant en
                        </Label>
                        <Input id="month" type="month" value={filters.month ?? ''} onChange={(e) => visit({ month: e.target.value || null })} />
                    </div>
                    <div className="flex items-end">
                        {filtered && (
                            <Button type="button" variant="ghost" size="sm" onClick={() => router.get(route('finance.to-invoice.index'))}>
                                Réinitialiser
                            </Button>
                        )}
                    </div>
                </section>

                {rows.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {filtered ? 'Rien à facturer pour ces filtres.' : 'Rien à facturer pour le moment.'}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    {can.manage && (
                                        <th className="w-10 px-3 py-2">
                                            <Checkbox
                                                aria-label="Tout sélectionner"
                                                checked={selectable.length > 0 && selected.length === selectable.length}
                                                onCheckedChange={(checked) => setSelected(checked === true ? selectable : [])}
                                            />
                                        </th>
                                    )}
                                    <th className="px-3 py-2 font-medium">Dossier</th>
                                    <th className="px-3 py-2 font-medium">Destinataire</th>
                                    <th className="px-3 py-2 font-medium">Rythme</th>
                                    <th className="px-3 py-2 font-medium">Période</th>
                                    <th className="px-3 py-2 text-right font-medium">Dû HT</th>
                                    <th className="px-3 py-2 font-medium">
                                        <span className="sr-only">Détail</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => {
                                    const expanded = open.includes(row.key);

                                    return [
                                        <tr key={row.key} className="border-t align-top">
                                            {can.manage && (
                                                <td className="px-3 py-2">
                                                    {row.draft === null && row.preparable && (
                                                        <Checkbox
                                                            aria-label={`Sélectionner ${row.dossier.number} - ${row.recipient.label}`}
                                                            checked={selected.includes(row.key)}
                                                            onCheckedChange={() => setSelected(toggle(selected, row.key))}
                                                        />
                                                    )}
                                                </td>
                                            )}
                                            <td className="px-3 py-2">
                                                <Link href={row.dossier.url} className="font-medium underline">
                                                    {row.dossier.number}
                                                </Link>
                                                <span className="text-muted-foreground block text-xs">{row.dossier.contractor}</span>
                                            </td>
                                            <td className="px-3 py-2">
                                                {row.recipient.label}
                                                <span className="text-muted-foreground block text-xs">
                                                    {row.recipient.role === 'contractor' ? 'Contractant' : 'Financeur payant directement'}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2">{row.rhythm_label}</td>
                                            <td className="px-3 py-2">{row.period.label}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                                                {formatCents(row.total_cents)}
                                                {row.kind === 'credit_note' && row.preparable && (
                                                    <Badge variant="destructive" className="ml-2">
                                                        Avoir proposé
                                                    </Badge>
                                                )}
                                                {row.blocked_reason && <span className="text-destructive block text-xs">{row.blocked_reason}</span>}
                                                {row.unsettled_cents !== 0 && (
                                                    <span className="text-muted-foreground block text-xs">
                                                        Reste à régulariser ensuite : {formatCents(row.unsettled_cents)}
                                                    </span>
                                                )}
                                                {row.draft && (
                                                    <Link href={row.draft.url} className="block text-xs underline">
                                                        Brouillon en cours
                                                    </Link>
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-expanded={expanded}
                                                    onClick={() => setOpen(toggle(open, row.key))}
                                                >
                                                    {expanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                                    Détail
                                                </Button>
                                            </td>
                                        </tr>,
                                        expanded && (
                                            <tr key={`${row.key}-detail`} className="bg-muted/20">
                                                <td colSpan={can.manage ? 7 : 6} className="px-3 py-3">
                                                    <Detail row={row} />
                                                </td>
                                            </tr>
                                        ),
                                    ];
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {soon.length > 0 && (
                    <section aria-labelledby="soon-title" className="grid gap-2">
                        <h2 id="soon-title" className="text-base font-semibold">
                            Bientôt à facturer
                        </h2>
                        <ul className="grid gap-1 text-sm">
                            {soon.map((s) => (
                                <li key={s.key} className="flex flex-wrap gap-x-2">
                                    <Link href={s.dossier.url} className="font-medium underline">
                                        {s.dossier.number}
                                    </Link>
                                    <span>{s.dossier.contractor}</span>
                                    <span className="text-muted-foreground">
                                        · {s.rhythm_label} · {s.reason}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
