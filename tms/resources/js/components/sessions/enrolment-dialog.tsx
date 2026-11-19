import InputError from '@/components/input-error';
import { emptyPerson, NewPersonFields, newPersonLabel, type NewPerson } from '@/components/sessions/new-person-fields';
import { pickOnEnter, SearchHint } from '@/components/sessions/search-hits';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDay } from '@/lib/crm';
import { sessionDates } from '@/lib/sessions';
import { router } from '@inertiajs/react';
import { LoaderCircle, Plus, X } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

/** A dossier offered on a session page (EnrolmentOptions::dossiersFor). */
export interface EnrolDossierOption {
    id: string;
    number: string;
    account: string | null;
    account_organisation_id: string | null;
    /** The particulier contractant: the only trainee of the dossier. */
    account_person_id: string | null;
    lines: { id: string; label: string }[];
}

/** A session offered for a line on a dossier page (EnrolmentOptions::sessionsFor). */
export interface EnrolSessionOption {
    id: string;
    number: string;
    title: string | null;
    starts_on: string;
    ends_on: string;
    kind_label: string;
    capacity: number | null;
    /** French decimal (« 35,00 »). */
    planned_hours: string | null;
}

interface Found {
    id: string;
    last_name: string;
    first_name: string | null;
    birth_date: string | null;
    organisation_label: string | null;
}

const foundLabel = (t: Found) =>
    [[t.first_name, t.last_name].filter(Boolean).join(' '), t.birth_date ? `né(e) le ${formatDay(t.birth_date)}` : null, t.organisation_label]
        .filter(Boolean)
        .join(' · ');

/** A trainee of the group: a person of the CRM, or one to create (by its ref); entry day and hours of his own. */
type Trainee = { key: number; entered_on: string; planned_hours: string } & ({ person: { id: string; label: string } } | { ref: string });

let nextKey = 1;

/**
 * Search among every person of the organisme (identity only, the employees
 * of the contractant first): a click adds the person to the group.
 */
function TraineeSearch({
    sessionId,
    organisationId,
    onPick,
}: {
    sessionId: string;
    organisationId: string | null;
    onPick: (p: { id: string; label: string }) => void;
}) {
    const [term, setTerm] = useState('');
    const [hits, setHits] = useState<Found[]>([]);
    const [searched, setSearched] = useState(false);

    useEffect(() => {
        setSearched(false);
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
                .then((json) => {
                    setSearched(true);
                    setHits((json?.personnes ?? []) as Found[]);
                })
                .catch(() => undefined);
        }, 250);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, sessionId, organisationId]);

    const pick = (h: Found) => {
        onPick({ id: h.id, label: foundLabel(h) });
        setTerm('');
        setHits([]);
    };

    return (
        <div className="grid gap-1">
            <Input
                id="trainee_search"
                type="search"
                value={term}
                onChange={(e) => setTerm(e.target.value)}
                onKeyDown={(e) => pickOnEnter(e, term, hits, foundLabel, pick)}
                placeholder="Nom, prénom ou e-mail (toutes les personnes de l'organisme)"
            />
            {hits.length > 0 && (
                <ul className="grid gap-1 rounded-md border p-1 text-sm">
                    {hits.map((h) => (
                        <li key={h.id}>
                            <button type="button" className="hover:bg-muted w-full rounded px-2 py-1 text-left" onClick={() => pick(h)}>
                                {foundLabel(h)}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <SearchHint term={term} count={hits.length} searched={searched} />
        </div>
    );
}

type Source =
    | { from: 'session'; sessionId: string; sessionHours: string | null; sessionStart: string; dossiers: EnrolDossierOption[] }
    | {
          from: 'dossier';
          dossierId: string;
          organisationId: string | null;
          organisationLabel: string | null;
          /** The particulier contractant of the dossier: the only trainee possible. */
          particulier: { id: string; label: string } | null;
          lines: { id: string; label: string }[];
          sessions: Record<string, EnrolSessionOption[]>;
      };

const sessionOptionLabel = (s: EnrolSessionOption) =>
    `${s.title ? `${s.title} · ` : ''}${s.number} · ${sessionDates(s)} · ${s.kind_label}${s.capacity !== null ? ` · ${s.capacity} places` : ''}`;

/**
 * « Inscrire des stagiaires »: from a session (choose the dossier and its
 * line) or from a dossier (choose the line and the session), then the
 * group - people of the CRM or created on the spot -, a common entry day
 * that each trainee may change, and his hours. One request, one
 * transaction: the server checks every trainee again (places, waiting
 * list, intra entreprise, duplicates) and answers the errors per trainee.
 */
export function EnrolDialog({ source, onClose }: { source: Source; onClose: () => void }) {
    const [dossierId, setDossierId] = useState(source.from === 'dossier' ? source.dossierId : '');
    const [lineId, setLineId] = useState('');
    const [sessionId, setSessionId] = useState(source.from === 'session' ? source.sessionId : '');
    const [enteredOn, setEnteredOn] = useState('');
    const [trainees, setTrainees] = useState<Trainee[]>([]);
    const [newPeople, setNewPeople] = useState<Record<string, NewPerson>>({});
    const [draft, setDraft] = useState<NewPerson | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const dossier = source.from === 'session' ? source.dossiers.find((d) => d.id === dossierId) : undefined;
    const lines = source.from === 'session' ? (dossier?.lines ?? []) : source.lines;
    const sessions = source.from === 'dossier' && lineId ? (source.sessions[lineId] ?? []) : [];
    const chosen = source.from === 'dossier' ? sessions.find((s) => s.id === sessionId) : undefined;
    const organisationId = source.from === 'session' ? (dossier?.account_organisation_id ?? null) : source.organisationId;
    const employer = source.from === 'session' ? (dossier?.account_organisation_id ? dossier.account : null) : source.organisationLabel;
    const hours = source.from === 'session' ? source.sessionHours : (chosen?.planned_hours ?? null);
    const start = source.from === 'session' ? source.sessionStart : (chosen?.starts_on ?? '');
    // A particulier signs for himself: he is the only trainee, chosen for the user.
    const particulier =
        source.from === 'session'
            ? dossier?.account_person_id
                ? { id: dossier.account_person_id, label: dossier.account ?? 'Particulier' }
                : null
            : source.particulier;
    useEffect(() => {
        setTrainees(particulier ? [{ key: nextKey++, person: particulier, entered_on: '', planned_hours: '' }] : []);
        setDraft(null);
        setErrors({});
    }, [particulier?.id]); // eslint-disable-line react-hooks/exhaustive-deps

    const label = (t: Trainee) => ('person' in t ? t.person.label : newPersonLabel(newPeople[t.ref]));
    // The errors of the trainees are keyed by their position: a removal shifts it, so they go with it.
    const remove = (key: number) => {
        setTrainees((ts) => ts.filter((x) => x.key !== key));
        setErrors((errs) => Object.fromEntries(Object.entries(errs).filter(([k]) => k !== 'trainees' && !k.startsWith('trainees.'))));
    };
    const change = (key: number, patch: Partial<Pick<Trainee, 'entered_on' | 'planned_hours'>>) =>
        setTrainees((ts) => ts.map((t) => (t.key === key ? { ...t, ...patch } : t)));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!sessionId || trainees.length === 0) {
            return;
        }
        router.post(
            route('sessions.enrolments.group', sessionId),
            {
                dossier_id: dossierId,
                dossier_line_id: lineId,
                entered_on: enteredOn,
                trainees: trainees.map((t) => ({
                    ...('person' in t ? { person_id: t.person.id } : { new_person: { ...newPeople[t.ref], ref: t.ref } }),
                    entered_on: t.entered_on,
                    planned_hours: t.planned_hours,
                })),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errs) => setErrors(errs as Record<string, string>),
                onSuccess: onClose,
            },
        );
    };

    const traineeErrors = (j: number) => Object.entries(errors).filter(([k]) => k === `trainees.${j}` || k.startsWith(`trainees.${j}.`));

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Inscrire des stagiaires</DialogTitle>
                        <DialogDescription>
                            Un ou plusieurs stagiaires d'un même dossier. Une session inter complète place les suivants en liste d'attente.
                        </DialogDescription>
                    </DialogHeader>

                    {source.from === 'session' && (
                        <div className="grid gap-2">
                            <Label htmlFor="dossier_id">Dossier *</Label>
                            <Select
                                value={dossierId || undefined}
                                onValueChange={(v) => {
                                    const ls = source.dossiers.find((d) => d.id === v)?.lines ?? [];
                                    setDossierId(v);
                                    setLineId(ls.length === 1 ? ls[0].id : '');
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
                                value={lineId || undefined}
                                onValueChange={(v) => {
                                    setLineId(v);
                                    if (source.from === 'dossier') setSessionId('');
                                }}
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

                    {source.from === 'dossier' && lineId && (
                        <div className="grid gap-2">
                            <Label htmlFor="training_session_id">Session *</Label>
                            <Select value={sessionId || undefined} onValueChange={setSessionId}>
                                <SelectTrigger id="training_session_id">
                                    <SelectValue placeholder={sessions.length === 0 ? 'Aucune session de cette formation' : 'Choisissez'} />
                                </SelectTrigger>
                                <SelectContent>
                                    {sessions.map((s) => (
                                        <SelectItem key={s.id} value={s.id}>
                                            {sessionOptionLabel(s)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    <InputError message={errors.training_session_id} />

                    {sessionId && (
                        <>
                            <div className="grid gap-2 sm:max-w-xs">
                                <Label htmlFor="group_entered_on">Date d'entrée commune</Label>
                                <Input id="group_entered_on" type="date" value={enteredOn} onChange={(e) => setEnteredOn(e.target.value)} />
                                <p className="text-muted-foreground text-xs">
                                    {start ? `Vide : début de la session (${formatDay(start)}).` : 'Vide : début de la session.'} Modifiable par
                                    stagiaire.
                                </p>
                                <InputError message={errors.entered_on} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="trainee_search">Stagiaires *</Label>
                                {trainees.length > 0 && (
                                    <ul className="grid gap-2" aria-label="Stagiaires à inscrire">
                                        {trainees.map((t, j) => (
                                            <li key={t.key} className="grid gap-2 rounded-md border px-3 py-2 text-sm">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="font-medium">{label(t)}</span>
                                                    {!particulier && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            aria-label={`Retirer ${label(t)}`}
                                                            onClick={() => remove(t.key)}
                                                        >
                                                            <X className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                </div>
                                                <div className="grid gap-2 sm:grid-cols-2">
                                                    <div className="grid gap-1">
                                                        <Label htmlFor={`entered_on_${t.key}`} className="text-xs">
                                                            Date d'entrée
                                                        </Label>
                                                        <Input
                                                            id={`entered_on_${t.key}`}
                                                            type="date"
                                                            value={t.entered_on}
                                                            onChange={(e) => change(t.key, { entered_on: e.target.value })}
                                                        />
                                                    </div>
                                                    <div className="grid gap-1">
                                                        <Label htmlFor={`planned_hours_${t.key}`} className="text-xs">
                                                            Heures prévues
                                                        </Label>
                                                        <Input
                                                            id={`planned_hours_${t.key}`}
                                                            inputMode="decimal"
                                                            value={t.planned_hours}
                                                            placeholder={hours ?? ''}
                                                            onChange={(e) => change(t.key, { planned_hours: e.target.value })}
                                                        />
                                                    </div>
                                                </div>
                                                {traineeErrors(j).map(([k, m]) => (
                                                    <InputError key={k} message={m} />
                                                ))}
                                                {'ref' in t && errors[`trainees.${j}`]?.startsWith('Des personnes similaires') && (
                                                    <label className="flex items-center gap-2 text-xs">
                                                        <input
                                                            type="checkbox"
                                                            checked={newPeople[t.ref]?.confirm_duplicate ?? false}
                                                            onChange={(e) =>
                                                                setNewPeople((p) => ({
                                                                    ...p,
                                                                    [t.ref]: { ...p[t.ref], confirm_duplicate: e.target.checked },
                                                                }))
                                                            }
                                                        />
                                                        Créer quand même
                                                    </label>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <InputError message={errors.trainees} />
                                {particulier ? (
                                    <p className="text-muted-foreground text-xs">
                                        Dossier de particulier : seul {particulier.label} peut être inscrit.
                                    </p>
                                ) : (
                                    <>
                                        <TraineeSearch
                                            sessionId={sessionId}
                                            organisationId={organisationId}
                                            onPick={(p) => {
                                                if (!trainees.some((t) => 'person' in t && t.person.id === p.id)) {
                                                    setTrainees((ts) => [...ts, { key: nextKey++, person: p, entered_on: '', planned_hours: '' }]);
                                                }
                                            }}
                                        />
                                        {draft === null ? (
                                            <div>
                                                <Button type="button" variant="outline" size="sm" onClick={() => setDraft(emptyPerson())}>
                                                    <Plus className="h-4 w-4" />
                                                    Créer un stagiaire
                                                </Button>
                                            </div>
                                        ) : (
                                            <NewPersonFields
                                                index={0}
                                                value={draft}
                                                employer={employer}
                                                onChange={(c) => setDraft({ ...draft, ...c })}
                                                onCancel={() => setDraft(null)}
                                                onAdd={() => {
                                                    const ref = `n${nextKey++}`;
                                                    setNewPeople((p) => ({ ...p, [ref]: draft }));
                                                    setTrainees((ts) => [...ts, { key: nextKey++, ref, entered_on: '', planned_hours: '' }]);
                                                    setDraft(null);
                                                }}
                                            />
                                        )}
                                    </>
                                )}
                            </div>
                        </>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Retour
                        </Button>
                        <Button type="submit" disabled={processing || trainees.length === 0 || !sessionId}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            {trainees.length > 1 ? `Inscrire les ${trainees.length} stagiaires` : 'Inscrire'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
