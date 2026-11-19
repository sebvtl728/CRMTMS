import { InvoiceSendDialog } from '@/components/finance/invoice-send-dialog';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/crm';
import { router } from '@inertiajs/react';
import { BellRing } from 'lucide-react';
import { useState } from 'react';

export interface InvoiceReminders {
    next_level: number | null;
    paused: { at: string | null; reason: string | null } | null;
    defaults: { to: string; subject: string; body: string } | null;
    history: { id: string; level: number; status_label: string; at: string | null; automatic: boolean }[];
    remaining: string;
}

/** « Relances » of an issued invoice: the level due, « Relancer », the pause and the history. */
export function RemindersPanel({
    invoiceId,
    title,
    reminders,
    canRemind,
    deliversNowhere,
}: {
    invoiceId: string;
    title: string;
    reminders: InvoiceReminders;
    canRemind: boolean;
    deliversNowhere: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const due = reminders.next_level !== null && reminders.defaults !== null;

    return (
        <section aria-label="Relances" className="grid gap-2 rounded-xl border p-4 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-semibold">Relances</h2>
                {canRemind && (
                    <div className="flex flex-wrap items-center gap-2">
                        {due && !reminders.paused && (
                            <Button type="button" size="sm" variant="outline" onClick={() => setOpen(true)}>
                                <BellRing className="h-4 w-4" />
                                Relancer (niveau {reminders.next_level})
                            </Button>
                        )}
                        {reminders.paused ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => router.delete(`/finance/invoices/${invoiceId}/reminders/pause`, { preserveScroll: true })}
                            >
                                Reprendre les relances
                            </Button>
                        ) : (
                            <span className="flex items-center gap-1">
                                <input
                                    aria-label="Motif de la suspension"
                                    placeholder="Motif (litige, échéancier…)"
                                    className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        router.post(`/finance/invoices/${invoiceId}/reminders/pause`, { reason }, { preserveScroll: true })
                                    }
                                >
                                    Suspendre les relances
                                </Button>
                            </span>
                        )}
                    </div>
                )}
            </div>
            {reminders.paused && (
                <p className="text-muted-foreground">
                    Relances suspendues{reminders.paused.at ? ` depuis le ${formatDateTime(reminders.paused.at)}` : ''}
                    {reminders.paused.reason ? ` : ${reminders.paused.reason}` : '.'}
                </p>
            )}
            {!reminders.paused && reminders.next_level !== null && (
                <p>
                    Niveau {reminders.next_level} à envoyer (reste dû {reminders.remaining}).
                </p>
            )}
            {reminders.history.length === 0 ? (
                <p className="text-muted-foreground">Aucune relance envoyée.</p>
            ) : (
                <ul className="grid gap-1">
                    {reminders.history.map((h) => (
                        <li key={h.id} className="flex flex-wrap justify-between gap-2 rounded-md border px-2 py-1">
                            <span>
                                Niveau {h.level}
                                {h.automatic ? ' (automatique)' : ''}
                            </span>
                            <span className="text-muted-foreground">
                                {h.status_label}
                                {h.at ? ` — ${formatDateTime(h.at)}` : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {canRemind && reminders.defaults && (
                <InvoiceSendDialog
                    invoiceId={invoiceId}
                    title={title}
                    defaults={{ ...reminders.defaults, delivers_nowhere: deliversNowhere }}
                    open={open}
                    onClose={() => setOpen(false)}
                    action={`/finance/invoices/${invoiceId}/remind`}
                    level={reminders.next_level ?? undefined}
                    heading={`Relancer ${title} (niveau ${reminders.next_level})`}
                />
            )}
        </section>
    );
}
