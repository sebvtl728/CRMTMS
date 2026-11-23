import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDay } from '@/lib/crm';
import { formatCents } from '@/lib/revenue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, HandCoins, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface PayoutRow {
    invoice_id: string;
    number: string;
    issued_on: string | null;
    url: string;
    recipient: string;
    cotraitant_id: string;
    cotraitant: string;
    owed_cents: number;
    paid_out_cents: number;
    left_cents: number;
    state: 'todo' | 'partial' | 'done' | 'over';
    state_label: string;
    buyer_paid: boolean;
    buyer_left_cents: number;
    payouts: { id: string; amount_cents: number; paid_on: string; reference: string | null }[];
}

interface Props {
    rows: PayoutRow[];
    totals: { left_cents: number };
    filters: { state: 'all' | 'open'; cotraitant: string | null };
    cotraitants: { value: string; label: string }[];
    today: string;
    status?: string;
}

const STATE_VARIANT: Record<PayoutRow['state'], 'default' | 'secondary' | 'outline' | 'destructive'> = {
    todo: 'default',
    partial: 'secondary',
    done: 'outline',
    over: 'destructive',
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'À reverser', href: '/finance/payouts' }];

/** « 1234,5 » for the amount field from cents. */
const amountInput = (cents: number) => (cents / 100).toFixed(2).replace('.', ',');

/** Finance › À reverser: what the mandataire owes the co-contractors of its free invoices (2026-11-20). */
export default function PayoutsIndex({ rows, totals, filters, cotraitants, today }: Props) {
    const [row, setRow] = useState<PayoutRow | null>(null);
    const form = useForm({ invoice_id: '', cotraitant_id: '', amount: '', paid_on: today, reference: '', notes: '' });

    const open = (r: PayoutRow) => {
        form.clearErrors();
        form.setData({
            invoice_id: r.invoice_id,
            cotraitant_id: r.cotraitant_id,
            amount: amountInput(r.left_cents),
            paid_on: today,
            reference: '',
            notes: '',
        });
        setRow(r);
    };
    const filter = (next: Partial<Props['filters']>) =>
        router.get('/finance/payouts', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    const query = new URLSearchParams({ state: filters.state, ...(filters.cotraitant ? { cotraitant: filters.cotraitant } : {}) });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="À reverser" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title="À reverser"
                        description="Part des co-traitants sur les factures libres émises comme mandataire du groupement : dû, déjà reversé, reste à reverser."
                    />
                    <Button variant="outline" asChild>
                        <a href={`/finance/payouts-export?${query.toString()}`}>
                            <Download className="h-4 w-4" />
                            Exporter (CSV)
                        </a>
                    </Button>
                </div>

                <p className="bg-muted/40 rounded-xl border p-3 text-sm">
                    Seules les lignes de facture qui <strong>désignent un co-traitant</strong> apparaissent ici (champ « Co-traitant » d'une ligne de
                    facture libre, avant validation). Pour chacune : le dû (TTC de la ligne, moins un avoir), ce qui est déjà reversé, le reste et son
                    état. « Marquer reversé » enregistre un virement ; un reversement saisi par erreur se supprime avec la corbeille à côté de son
                    montant.
                </p>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="state">Afficher</Label>
                        <select
                            id="state"
                            className="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
                            value={filters.state}
                            onChange={(e) => filter({ state: e.target.value as 'all' | 'open' })}
                        >
                            <option value="all">Toutes les lignes co-traitées</option>
                            <option value="open">Reste à reverser seulement</option>
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="cotraitant">Co-traitant</Label>
                        <select
                            id="cotraitant"
                            className="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
                            value={filters.cotraitant ?? ''}
                            onChange={(e) => filter({ cotraitant: e.target.value || null })}
                        >
                            <option value="">Tous</option>
                            {cotraitants.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="text-muted-foreground ml-auto text-sm">
                        Reste à reverser : <span className="text-foreground font-semibold tabular-nums">{formatCents(totals.left_cents)}</span>
                    </div>
                </div>

                {rows.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {filters.state === 'open' ? 'Tout est reversé.' : 'Aucune ligne de facture validée ne désigne de co-traitant pour le moment.'}
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Facture</th>
                                    <th className="px-3 py-2 font-medium">Client</th>
                                    <th className="px-3 py-2 font-medium">Co-traitant</th>
                                    <th className="px-3 py-2 text-right font-medium">Dû</th>
                                    <th className="px-3 py-2 text-right font-medium">Reversé</th>
                                    <th className="px-3 py-2 text-right font-medium">Reste</th>
                                    <th className="px-3 py-2 font-medium">État</th>
                                    <th className="px-3 py-2 font-medium">Client a réglé</th>
                                    <th className="px-3 py-2">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r) => (
                                    <tr key={`${r.invoice_id}-${r.cotraitant_id}`} className="border-t align-top">
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            <Link href={r.url} className="font-medium underline">
                                                {r.number}
                                            </Link>
                                            <span className="text-muted-foreground block text-xs">{formatDay(r.issued_on)}</span>
                                        </td>
                                        <td className="px-3 py-2">{r.recipient}</td>
                                        <td className="px-3 py-2">
                                            {r.cotraitant}
                                            {r.payouts.map((p) => (
                                                <span key={p.id} className="text-muted-foreground flex items-center gap-1 text-xs">
                                                    {formatCents(p.amount_cents)} le {formatDay(p.paid_on)}
                                                    {p.reference ? ` (${p.reference})` : ''}
                                                    <button
                                                        type="button"
                                                        aria-label="Supprimer ce reversement"
                                                        className="hover:text-destructive"
                                                        onClick={() => {
                                                            if (window.confirm('Supprimer ce reversement ?')) {
                                                                router.delete(`/finance/payouts/${p.id}`, { preserveScroll: true });
                                                            }
                                                        }}
                                                    >
                                                        <Trash2 className="h-3 w-3" />
                                                    </button>
                                                </span>
                                            ))}
                                        </td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">{formatCents(r.owed_cents)}</td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">{formatCents(r.paid_out_cents)}</td>
                                        <td className="px-3 py-2 text-right font-medium whitespace-nowrap tabular-nums">
                                            {formatCents(r.left_cents)}
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            <Badge variant={STATE_VARIANT[r.state]}>{r.state_label}</Badge>
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            {r.buyer_paid ? 'Oui' : `Non (reste ${formatCents(r.buyer_left_cents)})`}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {r.left_cents > 0 && (
                                                <Button type="button" size="sm" variant="outline" onClick={() => open(r)}>
                                                    <HandCoins className="h-4 w-4" />
                                                    Marquer reversé
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Dialog open={row !== null} onOpenChange={(o) => !o && setRow(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Reversement à {row?.cotraitant} - facture {row?.number}
                        </DialogTitle>
                    </DialogHeader>
                    <form
                        className="grid gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/finance/payouts', { preserveScroll: true, onSuccess: () => setRow(null) });
                        }}
                    >
                        <div className="grid gap-1">
                            <Label htmlFor="amount">Montant reversé (€)</Label>
                            <Input id="amount" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                            <InputError message={form.errors.amount} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="paid_on">Date du virement</Label>
                            <Input id="paid_on" type="date" value={form.data.paid_on} onChange={(e) => form.setData('paid_on', e.target.value)} />
                            <InputError message={form.errors.paid_on} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="reference">Référence (facultatif)</Label>
                            <Input id="reference" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                            <InputError message={form.errors.reference} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="notes">Note (facultatif)</Label>
                            <Input id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                            <InputError message={form.errors.notes} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setRow(null)}>
                                Annuler
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Enregistrer
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
