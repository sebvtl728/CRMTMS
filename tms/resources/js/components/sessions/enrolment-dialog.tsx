import InputError from '@/components/input-error';
import { EnrolmentDetailsFields, type EnrolmentReferences } from '@/components/sessions/enrolment-list';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDay } from '@/lib/crm';
import { sessionDates } from '@/lib/sessions';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

/** A dossier offered on a session page (EnrolmentOptions::dossiersFor). */
export interface EnrolDossierOption {
    id: string;
    number: string;
    account: string | null;
    account_organisation_id: string | null;
    lines: { id: string; label: string }[];
}

/** A session offered for a line on a dossier page (EnrolmentOptions::sessionsFor). */
export interface EnrolSessionOption {
    id: string;
    number: string;
    starts_on: string;
    ends_on: string;
    kind_label: string;
    capacity: number | null;
}

interface Trainee {
    id: string;
    last_name: string;
    first_name: string | null;
    birth_date: string | null;
    organisation_label: string | null;
}

const traineeLabel = (t: Trainee) =>
    [[t.first_name, t.last_name].filter(Boolean).join(' '), t.birth_date ? `né(e) le ${formatDay(t.birth_date)}` : null, t.organisation_label]
        .filter(Boolean)
        .join(' · ');

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * The trainee: search among every person of the organisme (identity only,
 * the employees of the contractant first), or creation on the spot (an
 * ordinary person of the CRM, the similar people offered first).
 */
function TraineePicker({
    sessionId,
    organisationId,
    value,
    onPick,
}: {
    sessionId: string;
    organisationId: string | null;
    value: { id: string; label: string } | null;
    onPick: (p: { id: string; label: string } | null) => void;
}) {
    const [term, setTerm] = useState('');
    const [hits, setHits] = useState<Trainee[]>([]);
    const [creating, setCreating] = useState(false);
    const [draft, setDraft] = useState({ last_name: '', first_name: '', birth_date: '', email: '' });
    const [candidates, setCandidates] = useState<Trainee[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (term.trim().length < 2) {
            setHits([]);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(() => {
            const params: Record<string, string> = { q: term };
            if (organisationId) params.organisation = organisationId;
            fetch(`${route('sessions.trainees', sessionId)}?${new URLSearchParams(params)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((json) => setHits((json?.personnes ?? []) as Trainee[]))
                .catch(() => undefined);
        }, 250);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, sessionId, organisationId]);

    const create = async (confirmDuplicate: boolean) => {
        setBusy(true);
        setErrors({});
        try {
            const response = await fetch(route('sessions.trainees.store', sessionId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ ...draft, organisation_id: organisationId, confirm_duplicate: confirmDuplicate }),
            });
            const json = (await response.json().catch(() => ({}))) as {
                personne?: { id: string; label: string };
                candidates?: Trainee[];
                errors?: Record<string, string[]>;
                message?: string;
            };
            if (response.status === 201 && json.personne) {
                onPick(json.personne);
                setCreating(false);
                setCandidates([]);
            } else if (response.status === 409) {
                setCandidates(json.candidates ?? []);
            } else if (response.status === 422) {
                setErrors(Object.fromEntries(Object.entries(json.errors ?? {}).map(([k, v]) => [k, v[0]])));
            } else {
                setErrors({ last_name: json.message ?? 'La création a échoué, réessayez.' });
            }
        } catch {
            setErrors({ last_name: 'La création a échoué, réessayez.' });
        } finally {
            setBusy(false);
        }
    };

    if (value) {
        return (
            <div className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm">
                <span>{value.label}</span>
                <Button type="button" variant="ghost" size="sm" onClick={() => onPick(null)}>
                    Changer
                </Button>
            </div>
        );
    }

    if (creating) {
        return (
            <div className="grid gap-2 rounded-md border p-3">
                <div className="grid gap-2 sm:grid-cols-2">
                    <div className="grid gap-1">
                        <Label htmlFor="trainee_last_name">Nom *</Label>
                        <Input id="trainee_last_name" value={draft.last_name} onChange={(e) => setDraft({ ...draft, last_name: e.target.value })} />
                        <InputError message={errors.last_name} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="trainee_first_name">Prénom</Label>
                        <Input
                            id="trainee_first_name"
                            value={draft.first_name}
                            onChange={(e) => setDraft({ ...draft, first_name: e.target.value })}
                        />
                        <InputError message={errors.first_name} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="trainee_birth_date">Date de naissance</Label>
                        <Input
                            id="trainee_birth_date"
                            type="date"
                            value={draft.birth_date}
                            onChange={(e) => setDraft({ ...draft, birth_date: e.target.value })}
                        />
                        <InputError message={errors.birth_date} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="trainee_email">E-mail</Label>
                        <Input id="trainee_email" type="email" value={draft.email} onChange={(e) => setDraft({ ...draft, email: e.target.value })} />
                        <InputError message={errors.email} />
                    </div>
                </div>
                {candidates.length > 0 && (
                    <div role="alert" className="grid gap-1 rounded-md border border-amber-500/50 p-2 text-sm">
                        <p>Des personnes similaires existent déjà :</p>
                        <ul className="grid gap-1">
                            {candidates.map((c) => (
                                <li key={c.id}>
                                    <button
                                        type="button"
                                        className="hover:bg-muted w-full rounded px-2 py-1 text-left"
                                        onClick={() => {
                                            onPick({ id: c.id, label: traineeLabel(c) });
                                            setCreating(false);
                                        }}
                                    >
                                        {traineeLabel(c)}
                                    </button>
                                </li>
                            ))}
                        </ul>
                        <Button type="button" size="sm" variant="outline" onClick={() => create(true)} disabled={busy}>
                            Créer quand même
                        </Button>
                    </div>
                )}
                <div className="flex gap-2">
                    <Button type="button" size="sm" onClick={() => create(false)} disabled={busy}>
                        {busy && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Créer le stagiaire
                    </Button>
                    <Button type="button" size="sm" variant="ghost" onClick={() => setCreating(false)}>
                        Retour à la recherche
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="grid gap-1">
            <Input
                id="trainee_search"
                type="search"
                value={term}
                onChange={(e) => setTerm(e.target.value)}
                placeholder="Nom, prénom ou e-mail (toutes les personnes de l'organisme)"
            />
            {hits.length > 0 && (
                <ul className="grid gap-1 rounded-md border p-1 text-sm">
                    {hits.map((h) => (
                        <li key={h.id}>
                            <button
                                type="button"
                                className="hover:bg-muted w-full rounded px-2 py-1 text-left"
                                onClick={() => onPick({ id: h.id, label: traineeLabel(h) })}
                            >
                                {traineeLabel(h)}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <Button type="button" size="sm" variant="link" className="justify-self-start px-0" onClick={() => setCreating(true)}>
                Personne introuvable : créer le stagiaire
            </Button>
        </div>
    );
}

type Source =
    | { from: 'session'; sessionId: string; dossiers: EnrolDossierOption[] }
    | {
          from: 'dossier';
          dossierId: string;
          organisationId: string | null;
          lines: { id: string; label: string }[];
          sessions: Record<string, EnrolSessionOption[]>;
      };

/**
 * « Inscrire un stagiaire »: from a session (choose the dossier and its
 * line) or from a dossier (choose the line and the session), then the
 * trainee and the details. The server checks everything again (training,
 * intra entreprise, duplicates, waiting list).
 */
export function EnrolDialog({ source, references, onClose }: { source: Source; references: EnrolmentReferences; onClose: () => void }) {
    const [trainee, setTrainee] = useState<{ id: string; label: string } | null>(null);
    const { data, setData, transform, post, processing, errors } = useForm({
        training_session_id: source.from === 'session' ? source.sessionId : '',
        dossier_id: source.from === 'dossier' ? source.dossierId : '',
        dossier_line_id: '',
        person_id: '',
        entered_on: '',
        bpf_category_id: '',
        adaptation_needs: '',
        notes: '',
    });

    const dossier = source.from === 'session' ? source.dossiers.find((d) => d.id === data.dossier_id) : undefined;
    const lines = source.from === 'session' ? (dossier?.lines ?? []) : source.lines;
    const sessions = source.from === 'dossier' && data.dossier_line_id ? (source.sessions[data.dossier_line_id] ?? []) : [];
    const organisationId = source.from === 'session' ? (dossier?.account_organisation_id ?? null) : source.organisationId;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((d) => ({ ...d, person_id: trainee?.id ?? '' }));
        post(route('sessions.enrolments.store', data.training_session_id || 'none'), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Inscrire un stagiaire</DialogTitle>
                        <DialogDescription>Une session inter complète place le stagiaire en liste d'attente.</DialogDescription>
                    </DialogHeader>

                    {source.from === 'session' && (
                        <div className="grid gap-2">
                            <Label htmlFor="dossier_id">Dossier *</Label>
                            <Select
                                value={data.dossier_id || undefined}
                                onValueChange={(v) => {
                                    const lines = source.dossiers.find((d) => d.id === v)?.lines ?? [];
                                    setData((d) => ({ ...d, dossier_id: v, dossier_line_id: lines.length === 1 ? lines[0].id : '' }));
                                }}
                            >
                                <SelectTrigger id="dossier_id">
                                    <SelectValue placeholder={source.dossiers.length === 0 ? 'Aucun dossier de cette formation' : 'Choisissez'} />
                                </SelectTrigger>
                                <SelectContent>
                                    {source.dossiers.map((d) => (
                                        <SelectItem key={d.id} value={d.id}>
                                            {d.number}
                                            {d.account ? ` · ${d.account}` : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.dossier_id} />
                        </div>
                    )}

                    {lines.length > 1 || source.from === 'dossier' ? (
                        <div className="grid gap-2">
                            <Label htmlFor="dossier_line_id">Ligne de formation *</Label>
                            <Select
                                value={data.dossier_line_id || undefined}
                                onValueChange={(v) =>
                                    setData((d) => ({ ...d, dossier_line_id: v, ...(source.from === 'dossier' ? { training_session_id: '' } : {}) }))
                                }
                            >
                                <SelectTrigger id="dossier_line_id">
                                    <SelectValue placeholder="Choisissez" />
                                </SelectTrigger>
                                <SelectContent>
                                    {lines.map((l) => (
                                        <SelectItem key={l.id} value={l.id}>
                                            {l.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.dossier_line_id} />
                        </div>
                    ) : (
                        <InputError message={errors.dossier_line_id} />
                    )}

                    {source.from === 'dossier' && data.dossier_line_id && (
                        <div className="grid gap-2">
                            <Label htmlFor="training_session_id">Session *</Label>
                            <Select value={data.training_session_id || undefined} onValueChange={(v) => setData('training_session_id', v)}>
                                <SelectTrigger id="training_session_id">
                                    <SelectValue placeholder={sessions.length === 0 ? 'Aucune session de cette formation' : 'Choisissez'} />
                                </SelectTrigger>
                                <SelectContent>
                                    {sessions.map((s) => (
                                        <SelectItem key={s.id} value={s.id}>
                                            {s.number} · {sessionDates(s)} · {s.kind_label}
                                            {s.capacity !== null ? ` · ${s.capacity} places` : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    <InputError message={errors.training_session_id} />

                    {data.training_session_id && (
                        <div className="grid gap-2">
                            <Label htmlFor="trainee_search">Stagiaire *</Label>
                            <TraineePicker sessionId={data.training_session_id} organisationId={organisationId} value={trainee} onPick={setTrainee} />
                            <InputError message={errors.person_id} />
                        </div>
                    )}

                    <EnrolmentDetailsFields
                        data={data}
                        setData={(key, value) => setData(key, value)}
                        errors={errors}
                        categories={references.bpf_category}
                    />

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" disabled={processing || !trainee || !data.training_session_id}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Inscrire
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
