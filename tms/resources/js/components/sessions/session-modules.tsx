import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDay, type Option } from '@/lib/crm';
import { router, useForm } from '@inertiajs/react';
import { LoaderCircle, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export interface SessionModuleRow {
    id: string;
    position: number;
    title: string;
    planned_minutes: number | null;
    /** « 24 h 30 » */
    planned: string | null;
    /** As typed in the form (« 3,5 »). */
    planned_hours: string;
    modality_id: string | null;
    modality: string | null;
    dating_mode: 'interval' | 'seances';
    dating_mode_label: string;
    starts_on: string | null;
    ends_on: string | null;
    /** An interval module outside the dates of the session (flagged, never moved). */
    out_of_range: boolean;
    /** Séances or hours entered: neither deleted nor switched to the other mode. */
    has_activity: boolean;
}

export interface SessionPlanning {
    modules: SessionModuleRow[];
    total: string | null;
    warnings: string[];
    can_manage: boolean;
    options: { modality: Option[]; dating_mode: Option[] } | null;
}

const NONE = '__none__';

function ModuleDialog({
    sessionId,
    module,
    options,
    session,
    onClose,
}: {
    sessionId: string;
    module: SessionModuleRow | null;
    options: NonNullable<SessionPlanning['options']>;
    session: { starts_on: string; ends_on: string };
    onClose: () => void;
}) {
    const { data, setData, post, put, processing, errors } = useForm({
        title: module?.title ?? '',
        planned_hours: module?.planned_hours ?? '',
        modality_id: module?.modality_id ?? '',
        dating_mode: module?.dating_mode ?? 'interval',
        starts_on: module?.starts_on ?? '',
        ends_on: module?.ends_on ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const visit = { preserveScroll: true, preserveState: true, onSuccess: onClose };
        if (module) {
            put(route('sessions.modules.update', [sessionId, module.id]), visit);
        } else {
            post(route('sessions.modules.store', sessionId), visit);
        }
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{module ? 'Modifier le module' : 'Ajouter un module'}</DialogTitle>
                        <DialogDescription>
                            Les heures prévues de la session sont la somme de ses modules. Le catalogue n'est pas modifié.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="module_title">Intitulé *</Label>
                        <Input id="module_title" value={data.title} maxLength={200} onChange={(e) => setData('title', e.target.value)} />
                        <InputError message={errors.title} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="module_hours">Heures prévues *</Label>
                            <Input
                                id="module_hours"
                                inputMode="decimal"
                                placeholder="ex. 7 ou 3,5"
                                value={data.planned_hours}
                                onChange={(e) => setData('planned_hours', e.target.value)}
                            />
                            <InputError message={errors.planned_hours} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="module_modality">Modalité</Label>
                            <Select value={data.modality_id || NONE} onValueChange={(v) => setData('modality_id', v === NONE ? '' : v)}>
                                <SelectTrigger id="module_modality">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Non précisée</SelectItem>
                                    {options.modality.map((o) => (
                                        <SelectItem key={o.value} value={o.value}>
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.modality_id} />
                        </div>
                    </div>
                    {!module && (
                        <div className="grid gap-2">
                            <Label htmlFor="module_mode">Mode de datation *</Label>
                            <Select value={data.dating_mode} onValueChange={(v) => setData('dating_mode', v as 'interval' | 'seances')}>
                                <SelectTrigger id="module_mode">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.dating_mode.map((o) => (
                                        <SelectItem key={o.value} value={o.value}>
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.dating_mode} />
                        </div>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="module_starts">Début</Label>
                            <Input
                                id="module_starts"
                                type="date"
                                min={session.starts_on}
                                max={session.ends_on}
                                value={data.starts_on}
                                onChange={(e) => setData('starts_on', e.target.value)}
                            />
                            <InputError message={errors.starts_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="module_ends">Fin</Label>
                            <Input
                                id="module_ends"
                                type="date"
                                min={data.starts_on || session.starts_on}
                                max={session.ends_on}
                                value={data.ends_on}
                                onChange={(e) => setData('ends_on', e.target.value)}
                            />
                            <InputError message={errors.ends_on} />
                        </div>
                    </div>
                    {data.dating_mode === 'interval' && (
                        <p className="text-muted-foreground text-xs">Sans dates, le module prend celles de la session.</p>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Enregistrer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** « Modules » section of the « Planning » tab of a session. */
export function SessionModules({
    sessionId,
    session,
    planning,
}: {
    sessionId: string;
    session: { starts_on: string; ends_on: string; planned_hours: string | null };
    planning: SessionPlanning;
}) {
    const [editing, setEditing] = useState<SessionModuleRow | 'new' | null>(null);
    const visit = { preserveScroll: true, preserveState: true };

    const destroy = (m: SessionModuleRow) => {
        if (window.confirm(`Supprimer le module « ${m.title} » ?`)) {
            router.delete(route('sessions.modules.destroy', [sessionId, m.id]), visit);
        }
    };

    const switchMode = (m: SessionModuleRow) => {
        const target = m.dating_mode === 'interval' ? 'seances' : 'interval';
        const label = target === 'seances' ? 'séances' : 'intervalle de dates';
        if (window.confirm(`Passer le module « ${m.title} » en ${label} ?`)) {
            router.post(route('sessions.modules.mode', [sessionId, m.id]), { dating_mode: target }, visit);
        }
    };

    return (
        <section aria-label="Modules" className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">
                    Modules · {planning.modules.length}
                    <span className="text-muted-foreground font-normal"> · total {planning.total ?? '—'}</span>
                </h2>
                {planning.can_manage && planning.options && (
                    <Button type="button" size="sm" onClick={() => setEditing('new')}>
                        <Plus className="h-4 w-4" />
                        Ajouter un module
                    </Button>
                )}
            </div>

            {planning.warnings.length > 0 && (
                <ul className="grid gap-1 rounded-xl border border-amber-500/40 bg-amber-500/5 p-3 text-sm" aria-label="Avertissements">
                    {planning.warnings.map((w) => (
                        <li key={w}>{w}</li>
                    ))}
                </ul>
            )}

            {planning.modules.length === 0 ? (
                <p className="text-muted-foreground text-sm">Aucun module.</p>
            ) : (
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Module</th>
                                <th className="px-3 py-2 font-medium">Mode</th>
                                <th className="px-3 py-2 font-medium">Heures prévues</th>
                                <th className="px-3 py-2 font-medium">Dates</th>
                                <th className="px-3 py-2 font-medium">Modalité</th>
                                {planning.can_manage && <th className="px-3 py-2 font-medium">Actions</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {planning.modules.map((m) => (
                                <tr key={m.id} className="border-t">
                                    <td className="px-3 py-2">{m.title}</td>
                                    <td className="px-3 py-2">{m.dating_mode_label}</td>
                                    <td className="px-3 py-2 whitespace-nowrap">{m.planned ?? '—'}</td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {m.starts_on ? `${formatDay(m.starts_on)} → ${formatDay(m.ends_on)}` : '—'}
                                        {m.out_of_range && (
                                            <Badge variant="destructive" className="ml-2">
                                                Hors des dates de la session
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">{m.modality ?? '—'}</td>
                                    {planning.can_manage && (
                                        <td className="px-3 py-2">
                                            <div className="flex flex-wrap gap-1">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => setEditing(m)}
                                                    aria-label={`Modifier ${m.title}`}
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    disabled={m.has_activity}
                                                    onClick={() => switchMode(m)}
                                                >
                                                    {m.dating_mode === 'interval' ? 'Passer en séances' : 'Passer en intervalle'}
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-destructive"
                                                    disabled={m.has_activity}
                                                    onClick={() => destroy(m)}
                                                    aria-label={`Supprimer ${m.title}`}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {editing !== null && planning.options && (
                <ModuleDialog
                    sessionId={sessionId}
                    module={editing === 'new' ? null : editing}
                    options={planning.options}
                    session={session}
                    onClose={() => setEditing(null)}
                />
            )}
        </section>
    );
}
