import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface PendingRow {
    id: string;
    name: string;
    listed_at: string;
    planned_at: string;
    last_contact_at: string | null;
    created_at: string | null;
    deleted: boolean;
}

interface KeptRow {
    id: string;
    name: string;
    until: string | null;
    reason: string | null;
}

interface GdprProps {
    pending: PendingRow[];
    pendingTotal: number;
    kept: KeptRow[];
    retention: { years: number; min: number; max: number; delay_days: number; can_update: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'RGPD', href: '/crm/gdpr' }];

export default function GdprIndex({ pending, pendingTotal, kept, retention }: GdprProps) {
    const [keeping, setKeeping] = useState<PendingRow | null>(null);
    const keepForm = useForm({ reason: '' });
    const retentionForm = useForm({ retention_years: String(retention.years) });

    const keep = (e: React.FormEvent) => {
        e.preventDefault();
        if (!keeping) return;
        keepForm.post(route('crm.gdpr.keep', keeping.id), {
            preserveScroll: true,
            onSuccess: () => {
                setKeeping(null);
                keepForm.reset();
            },
        });
    };

    const saveRetention = (e: React.FormEvent) => {
        e.preventDefault();
        retentionForm.put(route('crm.gdpr.retention'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="RGPD" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="RGPD"
                    description={`Les personnes sans relation contractuelle et sans contact depuis ${retention.years} an${retention.years > 1 ? 's' : ''} sont listées ici, puis anonymisées ${retention.delay_days} jours plus tard, sauf si vous les conservez.`}
                />

                <section aria-labelledby="pending-title" className="flex flex-col gap-3">
                    <h2 id="pending-title" className="text-base font-semibold">
                        À anonymiser ({pendingTotal})
                    </h2>
                    {pending.length === 0 ? (
                        <p className="text-muted-foreground rounded-xl border p-6 text-center text-sm">Aucune personne à anonymiser.</p>
                    ) : (
                        <>
                            <div className="hidden overflow-x-auto rounded-xl border md:block">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="px-4 py-2 font-medium">Personne</th>
                                            <th className="px-4 py-2 font-medium">Dernier contact</th>
                                            <th className="px-4 py-2 font-medium">Listée le</th>
                                            <th className="px-4 py-2 font-medium">Anonymisation prévue</th>
                                            <th className="px-4 py-2" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {pending.map((p) => (
                                            <tr key={p.id} className="border-t">
                                                <td className="px-4 py-2 font-medium">
                                                    <Link href={route('crm.people.show', p.id)} className="hover:underline">
                                                        {p.name}
                                                    </Link>
                                                    {p.deleted && (
                                                        <Badge variant="destructive" className="ml-2">
                                                            Supprimée
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {p.last_contact_at
                                                        ? formatDate(p.last_contact_at)
                                                        : `aucun (créée le ${formatDate(p.created_at)})`}
                                                </td>
                                                <td className="px-4 py-2">{formatDate(p.listed_at)}</td>
                                                <td className="px-4 py-2">{formatDate(p.planned_at)}</td>
                                                <td className="px-4 py-2 text-right">
                                                    <Button variant="outline" size="sm" onClick={() => setKeeping(p)}>
                                                        Conserver
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <ul className="grid gap-3 md:hidden">
                                {pending.map((p) => (
                                    <li key={p.id} className="rounded-xl border p-4">
                                        <Link href={route('crm.people.show', p.id)} className="font-medium hover:underline">
                                            {p.name}
                                        </Link>
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            Dernier contact : {p.last_contact_at ? formatDate(p.last_contact_at) : 'aucun'} · Anonymisation le{' '}
                                            {formatDate(p.planned_at)}
                                        </p>
                                        <Button variant="outline" size="sm" className="mt-2" onClick={() => setKeeping(p)}>
                                            Conserver
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                            {pendingTotal > pending.length && (
                                <p className="text-muted-foreground text-sm">
                                    {pending.length} premières personnes affichées sur {pendingTotal}.
                                </p>
                            )}
                        </>
                    )}
                </section>

                {kept.length > 0 && (
                    <section aria-labelledby="kept-title" className="flex flex-col gap-3">
                        <h2 id="kept-title" className="text-base font-semibold">
                            Conservées
                        </h2>
                        <ul className="grid gap-2">
                            {kept.map((k) => (
                                <li key={k.id} className="rounded-xl border p-3 text-sm">
                                    <Link href={route('crm.people.show', k.id)} className="font-medium hover:underline">
                                        {k.name}
                                    </Link>
                                    <span className="text-muted-foreground"> · jusqu’au {formatDate(k.until)}</span>
                                    {k.reason && <p className="text-muted-foreground mt-1 break-words">{k.reason}</p>}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section aria-labelledby="retention-title" className="flex max-w-xl flex-col gap-3">
                    <h2 id="retention-title" className="text-base font-semibold">
                        Durée de conservation
                    </h2>
                    {retention.can_update ? (
                        <form onSubmit={saveRetention} className="flex flex-col gap-2">
                            <Label htmlFor="retention_years">Années après le dernier contact</Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="retention_years"
                                    type="number"
                                    min={retention.min}
                                    max={retention.max}
                                    step={1}
                                    className="w-28"
                                    value={retentionForm.data.retention_years}
                                    onChange={(e) => retentionForm.setData('retention_years', e.target.value)}
                                />
                                <Button type="submit" disabled={retentionForm.processing}>
                                    Enregistrer
                                </Button>
                            </div>
                            <p className="text-muted-foreground text-sm">
                                Entre {retention.min} et {retention.max} ans (3 par défaut). Ne concerne pas les clients ni les interlocuteurs d’une
                                entreprise cliente.
                            </p>
                            <InputError message={retentionForm.errors.retention_years} />
                        </form>
                    ) : (
                        <p className="text-sm">
                            {retention.years} an{retention.years > 1 ? 's' : ''} après le dernier contact (modifiable par un administrateur).
                        </p>
                    )}
                </section>
            </div>

            <Dialog open={keeping !== null} onOpenChange={(open) => !open && setKeeping(null)}>
                <DialogContent>
                    <form onSubmit={keep} className="grid gap-4">
                        <DialogHeader>
                            <DialogTitle>Conserver « {keeping?.name} » ?</DialogTitle>
                            <DialogDescription>
                                La fiche sort de la liste pour {retention.years} an{retention.years > 1 ? 's' : ''}, puis sera réexaminée. Le motif
                                reste sur la fiche, visible des gestionnaires RGPD, et sera effacé si elle est anonymisée.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="reason">Motif</Label>
                            <Input
                                id="reason"
                                maxLength={500}
                                placeholder="Formation en cours, litige…"
                                value={keepForm.data.reason}
                                onChange={(e) => keepForm.setData('reason', e.target.value)}
                            />
                            <InputError message={keepForm.errors.reason} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setKeeping(null)}>
                                Annuler
                            </Button>
                            <Button type="submit" disabled={keepForm.processing}>
                                Conserver
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
