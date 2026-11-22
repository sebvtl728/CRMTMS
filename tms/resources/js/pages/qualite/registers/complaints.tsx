import Heading from '@/components/heading';
import { Choice, Field, LongText, type Option } from '@/components/qualite/register-fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

interface Complaint {
    id: string;
    received_on: string;
    channel_id: string;
    channel: string | null;
    complainant: string;
    subject: string;
    description: string | null;
    status: string;
    status_label: string;
    response: string | null;
    responded_on: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Réclamations', href: '/qualite/reclamations' }];

const empty = (channel: string) => ({
    received_on: new Date().toISOString().slice(0, 10),
    channel_id: channel,
    complainant: '',
    subject: '',
    description: '',
    status: 'open',
    response: '',
    responded_on: '',
});

/** Qualité › Réclamations: the register of indicator 31. */
export default function Complaints({
    complaints,
    options,
    can,
}: {
    complaints: Complaint[];
    options: { channels: Option[]; statuses: Option[] };
    can: { manage: boolean };
}) {
    const [editing, setEditing] = useState<Complaint | 'new' | null>(null);
    const form = useForm(empty(options.channels[0]?.value ?? ''));

    const open = (c: Complaint | 'new') => {
        form.clearErrors();
        form.setData(
            c === 'new'
                ? empty(options.channels[0]?.value ?? '')
                : {
                      received_on: c.received_on,
                      channel_id: c.channel_id,
                      complainant: c.complainant,
                      subject: c.subject,
                      description: c.description ?? '',
                      status: c.status,
                      response: c.response ?? '',
                      responded_on: c.responded_on ?? '',
                  },
        );
        setEditing(c);
    };
    const submit = () => {
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing === 'new') form.post('/qualite/reclamations', done);
        else if (editing) form.put(`/qualite/reclamations/${editing.id}`, done);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Réclamations" />
            <div className="grid gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading title="Réclamations" description="Registre des réclamations et de leur traitement (indicateur Qualiopi 31)." />
                    {can.manage && (
                        <Button onClick={() => open('new')}>
                            <Plus className="h-4 w-4" />
                            Nouvelle réclamation
                        </Button>
                    )}
                </div>
                {complaints.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Aucune réclamation enregistrée.</p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2">Reçue le</th>
                                    <th className="p-2">Objet</th>
                                    <th className="p-2">Émise par</th>
                                    <th className="p-2">Canal</th>
                                    <th className="p-2">État</th>
                                    <th className="p-2">Réponse le</th>
                                </tr>
                            </thead>
                            <tbody>
                                {complaints.map((c) => (
                                    <tr key={c.id} className="border-t">
                                        <td className="p-2">{formatDate(c.received_on)}</td>
                                        <td className="p-2">
                                            {can.manage ? (
                                                <button type="button" className="text-left underline" onClick={() => open(c)}>
                                                    {c.subject}
                                                </button>
                                            ) : (
                                                c.subject
                                            )}
                                        </td>
                                        <td className="p-2">{c.complainant}</td>
                                        <td className="p-2">{c.channel}</td>
                                        <td className="p-2">
                                            <Badge variant={c.status === 'closed' ? 'secondary' : 'outline'}>{c.status_label}</Badge>
                                        </td>
                                        <td className="p-2">{c.responded_on ? formatDate(c.responded_on) : '-'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editing === 'new' ? 'Nouvelle réclamation' : 'Réclamation'}</DialogTitle>
                        <DialogDescription>Une réclamation se clôt avec la réponse apportée et sa date.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-3">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                id="received_on"
                                label="Reçue le"
                                type="date"
                                value={form.data.received_on}
                                onChange={(v) => form.setData('received_on', v)}
                                error={form.errors.received_on}
                            />
                            <Choice
                                id="channel_id"
                                label="Canal"
                                value={form.data.channel_id}
                                options={options.channels}
                                onChange={(v) => form.setData('channel_id', v)}
                                error={form.errors.channel_id}
                            />
                        </div>
                        <Field
                            id="complainant"
                            label="Émise par"
                            value={form.data.complainant}
                            maxLength={200}
                            onChange={(v) => form.setData('complainant', v)}
                            error={form.errors.complainant}
                        />
                        <Field
                            id="subject"
                            label="Objet"
                            value={form.data.subject}
                            maxLength={200}
                            onChange={(v) => form.setData('subject', v)}
                            error={form.errors.subject}
                        />
                        <LongText
                            id="description"
                            label="Description"
                            value={form.data.description}
                            onChange={(v) => form.setData('description', v)}
                            error={form.errors.description}
                        />
                        <Choice
                            id="status"
                            label="État"
                            value={form.data.status}
                            options={options.statuses}
                            onChange={(v) => form.setData('status', v)}
                            error={form.errors.status}
                        />
                        <LongText
                            id="response"
                            label="Réponse apportée"
                            value={form.data.response}
                            onChange={(v) => form.setData('response', v)}
                            error={form.errors.response}
                        />
                        <Field
                            id="responded_on"
                            label="Répondue le"
                            type="date"
                            value={form.data.responded_on}
                            onChange={(v) => form.setData('responded_on', v)}
                            error={form.errors.responded_on}
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setEditing(null)}>
                            Annuler
                        </Button>
                        <Button onClick={submit} disabled={form.processing}>
                            Enregistrer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
