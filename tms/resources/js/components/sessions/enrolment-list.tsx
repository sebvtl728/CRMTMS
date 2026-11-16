import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDay, type Option } from '@/lib/crm';
import { sessionDates } from '@/lib/sessions';
import { Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

/** An enrolment as EnrolmentRows sends it. */
export interface EnrolmentRow {
    id: string;
    person: { id: string; label: string; can_open: boolean };
    session: { id: string; number: string; training: string | null; starts_on: string; ends_on: string; can_open: boolean } | null;
    /** Null when the viewer does not see the dossier (no number, no price). */
    dossier: { id: string; number: string; account: string | null } | null;
    line: { id: string; label: string } | null;
    status: 'enrolled' | 'waitlisted' | 'confirmed' | 'abandoned' | 'cancelled';
    status_label: string;
    waitlist_position: number | null;
    entered_on: string | null;
    left_on: string | null;
    abandon_reason: string | null;
    planned_hours: string | null;
    /** « 333,34 € », null: group price, waiting list, cancelled or dossier not visible. */
    price_share: string | null;
    bpf_category_id: string | null;
    bpf_category: string | null;
    adaptation_needs: string | null;
    notes: string | null;
    can_update: boolean;
}

export interface EnrolmentReferences {
    bpf_category: Option[];
    abandon_reason: Option[];
}

export const textareaClass =
    'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden';

export function enrolmentStatusVariant(status: EnrolmentRow['status']): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (status) {
        case 'confirmed':
            return 'default';
        case 'enrolled':
            return 'secondary';
        case 'abandoned':
        case 'cancelled':
            return 'destructive';
        default:
            return 'outline';
    }
}

function AbandonDialog({ row, reasons, onClose }: { row: EnrolmentRow; reasons: Option[]; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ left_on: '', abandon_reason_id: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('enrolments.abandon', row.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Abandon de {row.person.label}</DialogTitle>
                        <DialogDescription>La date de sortie et le motif sont obligatoires. Le stagiaire garde sa part du prix.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="left_on">Date de sortie *</Label>
                        <Input id="left_on" type="date" value={data.left_on} onChange={(e) => setData('left_on', e.target.value)} />
                        <InputError message={errors.left_on} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="abandon_reason_id">Motif d'abandon *</Label>
                        <Select value={data.abandon_reason_id || undefined} onValueChange={(v) => setData('abandon_reason_id', v)}>
                            <SelectTrigger id="abandon_reason_id">
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
                        <InputError message={errors.abandon_reason_id} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Enregistrer l'abandon
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditDialog({ row, categories, onClose }: { row: EnrolmentRow; categories: Option[]; onClose: () => void }) {
    const { data, setData, put, processing, errors } = useForm({
        entered_on: row.entered_on ?? '',
        bpf_category_id: row.bpf_category_id ?? '',
        adaptation_needs: row.adaptation_needs ?? '',
        notes: row.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('enrolments.update', row.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Inscription de {row.person.label}</DialogTitle>
                        <DialogDescription>Date d'entrée, catégorie BPF, besoins d'adaptation et notes.</DialogDescription>
                    </DialogHeader>
                    <EnrolmentDetailsFields data={data} setData={setData} errors={errors} categories={categories} withEntry />
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

interface Details {
    entered_on: string;
    bpf_category_id: string;
    adaptation_needs: string;
    notes: string;
}

/** Fields shared by the enrolment and edit dialogs. */
export function EnrolmentDetailsFields<T extends Details>({
    data,
    setData,
    errors,
    categories,
    withEntry = false,
}: {
    data: T;
    setData: (key: keyof Details, value: string) => void;
    errors: Partial<Record<string, string>>;
    categories: Option[];
    withEntry?: boolean;
}) {
    return (
        <>
            {withEntry && (
                <div className="grid gap-2">
                    <Label htmlFor="entered_on">Date d'entrée</Label>
                    <Input id="entered_on" type="date" value={data.entered_on} onChange={(e) => setData('entered_on', e.target.value)} />
                    <InputError message={errors.entered_on} />
                </div>
            )}
            <div className="grid gap-2">
                <Label htmlFor="bpf_category_id">Catégorie BPF</Label>
                <Select value={data.bpf_category_id || 'none'} onValueChange={(v) => setData('bpf_category_id', v === 'none' ? '' : v)}>
                    <SelectTrigger id="bpf_category_id">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">Non renseignée</SelectItem>
                        {categories.map((c) => (
                            <SelectItem key={c.value} value={c.value}>
                                {c.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.bpf_category_id} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="adaptation_needs">Situation de handicap, besoins d'adaptation</Label>
                <textarea
                    id="adaptation_needs"
                    rows={2}
                    value={data.adaptation_needs}
                    onChange={(e) => setData('adaptation_needs', e.target.value)}
                    className={textareaClass}
                />
                <p className="text-muted-foreground text-xs">Donnée personnelle sensible : facultative, jamais écrite dans le journal.</p>
                <InputError message={errors.adaptation_needs} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="enrolment_notes">Notes</Label>
                <textarea
                    id="enrolment_notes"
                    rows={2}
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                    className={textareaClass}
                />
                <InputError message={errors.notes} />
            </div>
        </>
    );
}

/**
 * Enrolments of a session (by dossier, waiting list first) or of a dossier
 * (by line): status, dates, share of the price, and the actions the user
 * may take (sessions.sessions.update on the session).
 */
export function EnrolmentList({
    rows,
    context,
    references,
}: {
    rows: EnrolmentRow[];
    context: 'session' | 'dossier';
    references: EnrolmentReferences | null;
}) {
    const [abandoning, setAbandoning] = useState<EnrolmentRow | null>(null);
    const [editing, setEditing] = useState<EnrolmentRow | null>(null);

    const act = (row: EnrolmentRow, action: 'confirm' | 'promote' | 'cancel') => {
        if (action === 'cancel' && !window.confirm(`Annuler l'inscription de ${row.person.label} ? Personne n'est promu automatiquement.`)) return;
        router.post(route('enrolments.transition', { enrolment: row.id, action }), {}, { preserveScroll: true });
    };

    if (rows.length === 0) {
        return <div className="text-muted-foreground rounded-xl border p-6 text-center text-sm">Aucun stagiaire inscrit.</div>;
    }

    return (
        <>
            <ul className="divide-y rounded-xl border">
                {rows.map((row) => (
                    <li key={row.id} className="grid gap-2 p-3 sm:grid-cols-[1fr_auto] sm:items-center">
                        <div className="grid gap-1">
                            <div className="flex flex-wrap items-center gap-2">
                                {row.person.can_open ? (
                                    <Link href={route('crm.people.show', row.person.id)} className="font-medium hover:underline">
                                        {row.person.label}
                                    </Link>
                                ) : (
                                    <span className="font-medium">{row.person.label}</span>
                                )}
                                <Badge variant={enrolmentStatusVariant(row.status)}>
                                    {row.status_label}
                                    {row.status === 'waitlisted' && row.waitlist_position !== null && ` · n° ${row.waitlist_position}`}
                                </Badge>
                                {row.price_share && <span className="text-sm tabular-nums">{row.price_share} HT</span>}
                            </div>
                            <p className="text-muted-foreground text-sm">
                                {[
                                    context === 'session'
                                        ? row.dossier
                                            ? `Dossier ${row.dossier.number}${row.dossier.account ? ` · ${row.dossier.account}` : ''}`
                                            : 'Dossier non visible'
                                        : row.session
                                          ? `Session ${row.session.number} · ${sessionDates(row.session)}`
                                          : null,
                                    row.entered_on && `entrée le ${formatDay(row.entered_on)}`,
                                    row.left_on && `sortie le ${formatDay(row.left_on)}`,
                                    row.abandon_reason && `motif : ${row.abandon_reason}`,
                                    row.bpf_category && `BPF : ${row.bpf_category}`,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                            {context === 'dossier' && row.session?.can_open && (
                                <Link href={route('sessions.show', row.session.id)} className="text-sm underline">
                                    Voir la session
                                </Link>
                            )}
                            {row.adaptation_needs && <p className="text-sm">Besoins d'adaptation : {row.adaptation_needs}</p>}
                        </div>
                        {row.can_update && references && row.status !== 'cancelled' && (
                            <div className="flex flex-wrap gap-1">
                                {row.status === 'enrolled' && (
                                    <Button type="button" size="sm" variant="outline" onClick={() => act(row, 'confirm')}>
                                        Confirmer
                                    </Button>
                                )}
                                {row.status === 'waitlisted' && (
                                    <Button type="button" size="sm" variant="outline" onClick={() => act(row, 'promote')}>
                                        Promouvoir
                                    </Button>
                                )}
                                {(row.status === 'enrolled' || row.status === 'confirmed') && (
                                    <Button type="button" size="sm" variant="outline" onClick={() => setAbandoning(row)}>
                                        Abandon
                                    </Button>
                                )}
                                <Button type="button" size="sm" variant="ghost" onClick={() => setEditing(row)}>
                                    Modifier
                                </Button>
                                <Button type="button" size="sm" variant="ghost" className="text-destructive" onClick={() => act(row, 'cancel')}>
                                    Désinscrire
                                </Button>
                            </div>
                        )}
                    </li>
                ))}
            </ul>
            {abandoning && references && <AbandonDialog row={abandoning} reasons={references.abandon_reason} onClose={() => setAbandoning(null)} />}
            {editing && references && <EditDialog row={editing} categories={references.bpf_category} onClose={() => setEditing(null)} />}
        </>
    );
}
