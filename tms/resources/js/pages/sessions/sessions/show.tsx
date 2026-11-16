import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatDay, type Option } from '@/lib/crm';
import { sessionDates, sessionLocation, sessionStatusVariant, type SessionRow } from '@/lib/sessions';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle, Pencil } from 'lucide-react';
import { FormEventHandler, useState, type ReactNode } from 'react';

interface ShowProps {
    session: SessionRow & {
        stored_status: string;
        notes: string | null;
        cancel_reason: string | null;
        cancelled_on: string | null;
        created_by: string | null;
        created_at: string | null;
    };
    can: { update: boolean; cancel: boolean; delete: boolean; restore: boolean };
    cancel_reasons: Option[];
    status?: string;
}

function CancelDialog({ sessionId, reasons, open, onClose }: { sessionId: string; reasons: Option[]; open: boolean; onClose: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({ cancel_reason_id: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sessions.cancel', sessionId), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Annuler la session</DialogTitle>
                        <DialogDescription>Une session annulée ne se modifie plus. Le motif est obligatoire.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="cancel_reason_id">Motif d'annulation *</Label>
                        <Select value={data.cancel_reason_id || undefined} onValueChange={(v) => setData('cancel_reason_id', v)}>
                            <SelectTrigger id="cancel_reason_id">
                                <SelectValue placeholder="Choisissez" />
                            </SelectTrigger>
                            <SelectContent>
                                {reasons.map((r) => (
                                    <SelectItem key={r.value} value={r.value}>
                                        {r.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.cancel_reason_id} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Annuler la session
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function SessionShow({ session, can, cancel_reasons, status }: ShowProps) {
    const [cancelling, setCancelling] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sessions', href: '/sessions' },
        { title: session.number, href: '#' },
    ];

    const destroy = () => {
        if (window.confirm(`Mettre la session ${session.number} à la corbeille ?`)) {
            router.delete(route('sessions.destroy', session.id));
        }
    };

    const field = (label: string, value: ReactNode) => (
        <div className="grid gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm">{value}</dd>
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Session ${session.number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="grid gap-2">
                        <Heading title={`Session ${session.number}`} description={session.training?.title ?? 'Formation introuvable'} />
                        <div className="flex flex-wrap gap-2">
                            <Badge variant={sessionStatusVariant(session.status)}>{session.status_label}</Badge>
                            <Badge variant="outline">{session.kind_label}</Badge>
                            {session.trashed && <Badge variant="destructive">Dans la corbeille</Badge>}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can.update && (
                            <Button asChild variant="outline">
                                <Link href={route('sessions.edit', session.id)}>
                                    <Pencil className="h-4 w-4" />
                                    Modifier
                                </Link>
                            </Button>
                        )}
                        {can.cancel && (
                            <Button type="button" variant="outline" onClick={() => setCancelling(true)}>
                                Annuler la session
                            </Button>
                        )}
                        {can.delete && (
                            <Button type="button" variant="ghost" className="text-destructive" onClick={destroy}>
                                Mettre à la corbeille
                            </Button>
                        )}
                        {can.restore && (
                            <Button type="button" onClick={() => router.post(route('sessions.restore', session.id))}>
                                Restaurer
                            </Button>
                        )}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                {session.status === 'cancelled' && (
                    <div className="border-destructive/40 bg-destructive/5 rounded-xl border p-3 text-sm">
                        Annulée le {formatDay(session.cancelled_on)} · Motif : {session.cancel_reason ?? '—'}
                    </div>
                )}

                <section aria-label="Informations" className="rounded-xl border p-4">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {field(
                            'Formation',
                            session.training ? (
                                <>
                                    {session.training.title}
                                    {session.training.code && <span className="text-muted-foreground"> ({session.training.code})</span>}
                                </>
                            ) : (
                                '—'
                            ),
                        )}
                        {field('DAS', session.das ?? '—')}
                        {field('Dates', sessionDates(session))}
                        {field('Heures prévues', session.planned_hours ? `${session.planned_hours} h` : '—')}
                        {field('Lieu', sessionLocation(session))}
                        {session.kind === 'intra'
                            ? field(
                                  'Entreprise',
                                  session.organisation ? (
                                      <Link href={route('crm.organisations.show', session.organisation.id)} className="hover:underline">
                                          {session.organisation.label}
                                          {session.organisation.trashed && ' (supprimée)'}
                                      </Link>
                                  ) : (
                                      '—'
                                  ),
                              )
                            : field('Places', session.capacity ?? '—')}
                        {field('Responsable', session.responsible ?? '—')}
                        {field('Créée', `${formatDateTime(session.created_at)}${session.created_by ? ` par ${session.created_by}` : ''}`)}
                    </dl>
                    {session.notes && (
                        <div className="mt-4 grid gap-0.5">
                            <div className="text-muted-foreground text-xs">Notes</div>
                            <p className="text-sm whitespace-pre-line">{session.notes}</p>
                        </div>
                    )}
                </section>
            </div>

            {can.cancel && <CancelDialog sessionId={session.id} reasons={cancel_reasons} open={cancelling} onClose={() => setCancelling(false)} />}
        </AppLayout>
    );
}
