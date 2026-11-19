import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatDay } from '@/lib/crm';
import { formatCents } from '@/lib/revenue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { BellRing } from 'lucide-react';
import { useState } from 'react';

interface ReminderRow {
    id: string;
    title: string;
    url: string;
    recipient: string;
    dossier: { id: string; number: string; url: string } | null;
    due_on: string | null;
    days_late: number;
    level: number;
    remaining_cents: number;
    email: string | null;
    last: { level: number; at: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'À relancer', href: '/finance/reminders' }];

/** Finance › À relancer: the unpaid invoices whose next reminder level is reached. */
export default function RemindersIndex({ reminders, status }: { reminders: ReminderRow[]; status?: string }) {
    const sendable = reminders.filter((r) => r.email !== null).map((r) => r.id);
    const [chosen, setChosen] = useState<string[]>(sendable);
    const [sending, setSending] = useState(false);
    const toggle = (id: string) => setChosen((c) => (c.includes(id) ? c.filter((x) => x !== id) : [...c, id]));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="À relancer" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title="À relancer"
                        description="Factures impayées dont le prochain niveau de relance est atteint (délais dans Finance › Réglages de facturation)."
                    />
                    <Button
                        type="button"
                        disabled={chosen.length === 0 || sending}
                        onClick={() =>
                            router.post(
                                '/finance/reminders/send',
                                { reminders: reminders.filter((r) => chosen.includes(r.id)).map((r) => ({ id: r.id, level: r.level })) },
                                { preserveScroll: true, onStart: () => setSending(true), onFinish: () => setSending(false) },
                            )
                        }
                    >
                        <BellRing className="h-4 w-4" />
                        Envoyer les relances ({chosen.length})
                    </Button>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                {reminders.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">Aucune facture à relancer aujourd'hui.</div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2">
                                        <span className="sr-only">Choisir</span>
                                    </th>
                                    <th className="px-3 py-2 font-medium">Facture</th>
                                    <th className="px-3 py-2 font-medium">Destinataire</th>
                                    <th className="px-3 py-2 font-medium">Échéance</th>
                                    <th className="px-3 py-2 text-right font-medium">Retard</th>
                                    <th className="px-3 py-2 text-right font-medium">Reste dû</th>
                                    <th className="px-3 py-2 font-medium">Niveau</th>
                                    <th className="px-3 py-2 font-medium">Dernière relance</th>
                                </tr>
                            </thead>
                            <tbody>
                                {reminders.map((r) => (
                                    <tr key={r.id} className="border-t">
                                        <td className="px-3 py-2">
                                            <input
                                                type="checkbox"
                                                aria-label={`Relancer ${r.title}`}
                                                disabled={r.email === null}
                                                checked={chosen.includes(r.id)}
                                                onChange={() => toggle(r.id)}
                                            />
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            <Link href={r.url} className="font-medium underline">
                                                {r.title}
                                            </Link>
                                            {r.dossier && <span className="text-muted-foreground block text-xs">{r.dossier.number}</span>}
                                        </td>
                                        <td className="px-3 py-2">
                                            {r.recipient}
                                            <span className="text-muted-foreground block text-xs">{r.email ?? 'Pas d’adresse e-mail'}</span>
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap">{formatDay(r.due_on)}</td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">{r.days_late} j</td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">{formatCents(r.remaining_cents)}</td>
                                        <td className="px-3 py-2">{r.level}</td>
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            {r.last ? `Niveau ${r.last.level}, ${formatDateTime(r.last.at)}` : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
