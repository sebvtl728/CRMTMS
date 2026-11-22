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

interface Action {
    id: string;
    title: string;
    description: string | null;
    source_id: string;
    source: string | null;
    complaint_id: string | null;
    complaint: { id: string; subject: string } | null;
    responsible_user_id: string | null;
    responsible: string | null;
    due_on: string | null;
    status: string;
    status_label: string;
    done_on: string | null;
    result: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Amélioration continue', href: '/qualite/amelioration' }];

const empty = (source: string) => ({
    title: '',
    description: '',
    source_id: source,
    complaint_id: '',
    responsible_user_id: '',
    due_on: '',
    status: 'planned',
    done_on: '',
    result: '',
});

/** Qualité › Amélioration continue: the improvement actions of indicator 32. */
export default function Actions({
    actions,
    options,
    can,
}: {
    actions: Action[];
    options: { sources: Option[]; statuses: Option[]; complaints: Option[]; users: Option[] };
    can: { manage: boolean };
}) {
    const [editing, setEditing] = useState<Action | 'new' | null>(null);
    const form = useForm(empty(options.sources[0]?.value ?? ''));
    const today = new Date().toISOString().slice(0, 10);

    const open = (a: Action | 'new') => {
        form.clearErrors();
        form.setData(
            a === 'new'
                ? empty(options.sources[0]?.value ?? '')
                : {
                      title: a.title,
                      description: a.description ?? '',
                      source_id: a.source_id,
                      complaint_id: a.complaint_id ?? '',
                      responsible_user_id: a.responsible_user_id ?? '',
                      due_on: a.due_on ?? '',
                      status: a.status,
                      done_on: a.done_on ?? '',
                      result: a.result ?? '',
                  },
        );
        setEditing(a);
    };
    const submit = () => {
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing === 'new') form.post('/qualite/amelioration', done);
        else if (editing) form.put(`/qualite/amelioration/${editing.id}`, done);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Amélioration continue" />
            <div className="grid gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title="Amélioration continue"
                        description="Actions d'amélioration issues des réclamations, questionnaires, audits et de la veille (indicateur Qualiopi 32)."
                    />
                    {can.manage && (
                        <Button onClick={() => open('new')}>
                            <Plus className="h-4 w-4" />
                            Nouvelle action
                        </Button>
                    )}
                </div>
                {actions.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Aucune action d'amélioration enregistrée.</p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2">Action</th>
                                    <th className="p-2">Origine</th>
                                    <th className="p-2">Responsable</th>
                                    <th className="p-2">Échéance</th>
                                    <th className="p-2">État</th>
                                </tr>
                            </thead>
                            <tbody>
                                {actions.map((a) => {
                                    const late = a.status !== 'done' && a.status !== 'abandoned' && a.due_on !== null && a.due_on < today;
                                    return (
                                        <tr key={a.id} className="border-t">
                                            <td className="p-2">
                                                {can.manage ? (
                                                    <button type="button" className="text-left underline" onClick={() => open(a)}>
                                                        {a.title}
                                                    </button>
                                                ) : (
                                                    a.title
                                                )}
                                                {a.complaint && (
                                                    <div className="text-muted-foreground text-xs">Réclamation : {a.complaint.subject}</div>
                                                )}
                                            </td>
                                            <td className="p-2">{a.source}</td>
                                            <td className="p-2">{a.responsible ?? '-'}</td>
                                            <td className={`p-2 ${late ? 'font-medium text-red-600 dark:text-red-400' : ''}`}>
                                                {a.due_on ? formatDate(a.due_on) : '-'}
                                                {late && ' (en retard)'}
                                            </td>
                                            <td className="p-2">
                                                <Badge variant={a.status === 'done' ? 'secondary' : 'outline'}>{a.status_label}</Badge>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editing === 'new' ? 'Nouvelle action' : "Action d'amélioration"}</DialogTitle>
                        <DialogDescription>Une action réalisée se clôt avec sa date et son résultat.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-3">
                        <Field
                            id="title"
                            label="Action"
                            value={form.data.title}
                            maxLength={200}
                            onChange={(v) => form.setData('title', v)}
                            error={form.errors.title}
                        />
                        <LongText
                            id="description"
                            label="Description"
                            value={form.data.description}
                            onChange={(v) => form.setData('description', v)}
                            error={form.errors.description}
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Choice
                                id="source_id"
                                label="Origine"
                                value={form.data.source_id}
                                options={options.sources}
                                onChange={(v) => form.setData('source_id', v)}
                                error={form.errors.source_id}
                            />
                            <Choice
                                id="complaint_id"
                                label="Réclamation liée"
                                value={form.data.complaint_id}
                                options={options.complaints}
                                none="Aucune"
                                onChange={(v) => form.setData('complaint_id', v)}
                                error={form.errors.complaint_id}
                            />
                            <Choice
                                id="responsible_user_id"
                                label="Responsable"
                                value={form.data.responsible_user_id}
                                options={options.users}
                                none="Aucun"
                                onChange={(v) => form.setData('responsible_user_id', v)}
                                error={form.errors.responsible_user_id}
                            />
                            <Field
                                id="due_on"
                                label="Échéance"
                                type="date"
                                value={form.data.due_on}
                                onChange={(v) => form.setData('due_on', v)}
                                error={form.errors.due_on}
                            />
                        </div>
                        <Choice
                            id="status"
                            label="État"
                            value={form.data.status}
                            options={options.statuses}
                            onChange={(v) => form.setData('status', v)}
                            error={form.errors.status}
                        />
                        <Field
                            id="done_on"
                            label="Réalisée le"
                            type="date"
                            value={form.data.done_on}
                            onChange={(v) => form.setData('done_on', v)}
                            error={form.errors.done_on}
                        />
                        <LongText
                            id="result"
                            label="Résultat"
                            value={form.data.result}
                            onChange={(v) => form.setData('result', v)}
                            error={form.errors.result}
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
