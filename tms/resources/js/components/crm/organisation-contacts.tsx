import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDate, formatPhone, todayIso, type Option } from '@/lib/crm';
import { Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface AffiliationRow {
    id: string;
    person: { id: string; name: string; civility: string | null };
    job_title: string | null;
    decision_role_id: string | null;
    decision_role: { id: string; label: string } | null;
    work_email: string | null;
    work_phone: string | null;
    is_referent: boolean;
    started_on: string | null;
    ended_on: string | null;
}

interface Props {
    organisationId: string;
    affiliations: AffiliationRow[];
    former: AffiliationRow[];
    decisionRoles: Option[];
    can: { affiliate: boolean; createPerson: boolean };
}

interface SearchHit {
    id: string;
    name: string;
    email: string | null;
    organisations: string[];
}

type AffiliationForm = {
    person_id: string;
    civility: string;
    first_name: string;
    last_name: string;
    job_title: string;
    decision_role_id: string;
    work_email: string;
    work_phone: string;
    is_referent: boolean;
    started_on: string;
    ended_on: string;
};

const NONE = 'none';

function Field({ id, label, error, children, className }: { id: string; label: string; error?: string; children: React.ReactNode; className?: string }) {
    return (
        <div className={`grid gap-1.5 ${className ?? ''}`}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

/** Contact links of an affiliation line. */
function ContactLinks({ email, phone }: { email: string | null; phone: string | null }) {
    if (!email && !phone) return <span className="text-muted-foreground">—</span>;

    return (
        <div className="flex flex-col gap-0.5 break-all">
            {email && (
                <a href={`mailto:${email}`} className="underline">
                    {email}
                </a>
            )}
            {phone && (
                <a href={`tel:${phone.replace(/[^+\d]/g, '')}`} className="whitespace-nowrap underline">
                    {formatPhone(phone)}
                </a>
            )}
        </div>
    );
}

/**
 * Create (new person or existing one) or edit an affiliation. In creation the
 * person is either typed in (new) or looked up (existing).
 */
function AffiliationDialog({
    organisationId,
    editing,
    decisionRoles,
    canCreatePerson,
    onClose,
}: {
    organisationId: string;
    editing: AffiliationRow | null;
    decisionRoles: Option[];
    canCreatePerson: boolean;
    onClose: () => void;
}) {
    const [mode, setMode] = useState<'new' | 'existing'>(canCreatePerson ? 'new' : 'existing');
    const [search, setSearch] = useState('');
    const [hits, setHits] = useState<SearchHit[]>([]);
    const [picked, setPicked] = useState<SearchHit | null>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const { data, setData, post, put, processing, errors, transform } = useForm<AffiliationForm>({
        person_id: '',
        civility: '',
        first_name: '',
        last_name: '',
        job_title: editing?.job_title ?? '',
        decision_role_id: editing?.decision_role_id ?? '',
        work_email: editing?.work_email ?? '',
        work_phone: editing?.work_phone ?? '',
        is_referent: editing?.is_referent ?? false,
        started_on: editing?.started_on ?? '',
        ended_on: editing?.ended_on ?? '',
    });

    transform((d) => {
        if (editing) {
            return {
                job_title: d.job_title,
                decision_role_id: d.decision_role_id,
                work_email: d.work_email,
                work_phone: d.work_phone,
                is_referent: d.is_referent,
                started_on: d.started_on,
                ended_on: d.ended_on,
            } as AffiliationForm;
        }

        return mode === 'existing' ? { ...d, civility: '', first_name: '', last_name: '' } : { ...d, person_id: '' };
    });

    // Debounced lookup of the people that can be attached.
    useEffect(() => {
        if (editing || mode !== 'existing' || picked || search.trim().length < 2) {
            setHits([]);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(route('crm.people.search', { q: search.trim(), organisation: organisationId }), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : { people: [] }))
                .then((json: { people: SearchHit[] }) => setHits(json.people))
                .catch(() => undefined);
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [search, mode, picked, editing, organisationId]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (editing) {
            put(route('crm.affiliations.update', editing.id), options);
        } else {
            post(route('crm.affiliations.store', organisationId), options);
        }
    };

    const roleValue = data.decision_role_id || NONE;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{editing ? `Modifier le rattachement de ${editing.person.name}` : 'Ajouter un interlocuteur'}</DialogTitle>
                    <DialogDescription>
                        {editing ? 'Fonction, coordonnées professionnelles et dates dans cette entreprise.' : 'Seul le nom de la personne est obligatoire.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-4" noValidate>
                    {!editing && (
                        <div role="group" aria-label="Personne" className="grid grid-cols-2 gap-1 rounded-lg border p-1">
                            {canCreatePerson && (
                                <Button type="button" size="sm" variant={mode === 'new' ? 'default' : 'ghost'} onClick={() => setMode('new')}>
                                    Nouvelle personne
                                </Button>
                            )}
                            <Button
                                type="button"
                                size="sm"
                                variant={mode === 'existing' ? 'default' : 'ghost'}
                                className={canCreatePerson ? '' : 'col-span-2'}
                                onClick={() => setMode('existing')}
                            >
                                Personne existante
                            </Button>
                        </div>
                    )}

                    {!editing && mode === 'new' && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field id="aff-civility" label="Civilité" error={errors.civility}>
                                <Select value={data.civility || NONE} onValueChange={(v) => setData('civility', v === NONE ? '' : v)}>
                                    <SelectTrigger id="aff-civility">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>Non précisée</SelectItem>
                                        <SelectItem value="M.">M.</SelectItem>
                                        <SelectItem value="Mme">Mme</SelectItem>
                                    </SelectContent>
                                </Select>
                            </Field>
                            <div className="hidden sm:block" />
                            <Field id="aff-first-name" label="Prénom" error={errors.first_name}>
                                <Input id="aff-first-name" value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} autoComplete="off" />
                            </Field>
                            <Field id="aff-last-name" label="Nom *" error={errors.last_name}>
                                <Input
                                    id="aff-last-name"
                                    value={data.last_name}
                                    onChange={(e) => setData('last_name', e.target.value)}
                                    required
                                    autoFocus
                                    autoComplete="off"
                                />
                            </Field>
                        </div>
                    )}

                    {!editing && mode === 'existing' && (
                        <div className="grid gap-2">
                            <Label htmlFor="aff-search">Rechercher une personne</Label>
                            {picked ? (
                                <div className="flex items-center justify-between gap-2 rounded-lg border p-2 text-sm">
                                    <span>
                                        <span className="font-medium">{picked.name}</span>
                                        {picked.email && <span className="text-muted-foreground"> · {picked.email}</span>}
                                    </span>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => {
                                            setPicked(null);
                                            setData('person_id', '');
                                        }}
                                    >
                                        Changer
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <Input
                                        id="aff-search"
                                        type="search"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder="Nom, e-mail, téléphone…"
                                        autoComplete="off"
                                        autoFocus
                                    />
                                    {hits.length > 0 && (
                                        <ul className="max-h-48 divide-y overflow-y-auto rounded-lg border text-sm" aria-label="Résultats">
                                            {hits.map((h) => (
                                                <li key={h.id}>
                                                    <button
                                                        type="button"
                                                        className="hover:bg-muted/50 w-full px-3 py-2 text-left"
                                                        onClick={() => {
                                                            setPicked(h);
                                                            setData('person_id', h.id);
                                                        }}
                                                    >
                                                        <span className="font-medium">{h.name}</span>
                                                        <span className="text-muted-foreground block text-xs">
                                                            {[h.email, h.organisations.join(', ')].filter(Boolean).join(' · ') || '—'}
                                                        </span>
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {search.trim().length >= 2 && hits.length === 0 && (
                                        <p className="text-muted-foreground text-xs">Aucune personne trouvée (ou déjà rattachée à cette entreprise).</p>
                                    )}
                                </>
                            )}
                            <InputError message={errors.person_id} />
                        </div>
                    )}

                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field id="aff-job" label="Fonction" error={errors.job_title}>
                            <Input id="aff-job" value={data.job_title} onChange={(e) => setData('job_title', e.target.value)} placeholder="Directeur, gérante…" />
                        </Field>
                        <Field id="aff-role" label="Rôle dans la décision" error={errors.decision_role_id}>
                            <Select value={roleValue} onValueChange={(v) => setData('decision_role_id', v === NONE ? '' : v)}>
                                <SelectTrigger id="aff-role">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Non renseigné</SelectItem>
                                    {decisionRoles.map((r) => (
                                        <SelectItem key={r.value} value={r.value}>
                                            {r.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field id="aff-email" label="E-mail professionnel" error={errors.work_email}>
                            <Input id="aff-email" type="email" value={data.work_email} onChange={(e) => setData('work_email', e.target.value)} autoComplete="off" />
                        </Field>
                        <Field id="aff-phone" label="Téléphone professionnel" error={errors.work_phone}>
                            <Input id="aff-phone" type="tel" value={data.work_phone} onChange={(e) => setData('work_phone', e.target.value)} maxLength={30} autoComplete="off" />
                        </Field>
                        <Field id="aff-started" label="Début du rattachement" error={errors.started_on}>
                            <Input id="aff-started" type="date" value={data.started_on} onChange={(e) => setData('started_on', e.target.value)} />
                        </Field>
                        {editing && (
                            <Field id="aff-ended" label="Fin du rattachement" error={errors.ended_on}>
                                <Input id="aff-ended" type="date" value={data.ended_on} onChange={(e) => setData('ended_on', e.target.value)} />
                            </Field>
                        )}
                    </div>

                    <Label className="flex items-center gap-2 font-normal">
                        <Checkbox checked={data.is_referent} onCheckedChange={(c) => setData('is_referent', c === true)} />
                        Interlocuteur référent de l'entreprise
                    </Label>

                    <DialogFooter className="gap-2 sm:justify-between">
                        {editing ? (
                            confirmDelete ? (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={() => router.delete(route('crm.affiliations.destroy', editing.id), { preserveScroll: true, onSuccess: onClose })}
                                >
                                    Confirmer la suppression
                                </Button>
                            ) : (
                                <Button type="button" variant="ghost" onClick={() => setConfirmDelete(true)}>
                                    Supprimer le rattachement
                                </Button>
                            )
                        ) : (
                            <span />
                        )}
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" onClick={onClose}>
                                Annuler
                            </Button>
                            <Button type="submit" disabled={processing || (!editing && mode === 'existing' && !picked)}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Enregistrer
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Confirmation of the end of an affiliation (default: today). */
function EndDialog({ affiliation, onClose }: { affiliation: AffiliationRow; onClose: () => void }) {
    const { data, setData, put, processing, errors } = useForm({ ended_on: todayIso() });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Mettre fin au rattachement ?</DialogTitle>
                    <DialogDescription>
                        {affiliation.person.name} ne sera plus listé comme interlocuteur actif. La personne reste enregistrée et le rattachement reste
                        visible dans l'historique.
                    </DialogDescription>
                </DialogHeader>
                <Field id="end-date" label="Date de fin" error={errors.ended_on}>
                    <Input id="end-date" type="date" value={data.ended_on} onChange={(e) => setData('ended_on', e.target.value)} />
                </Field>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button
                        disabled={processing || !data.ended_on}
                        onClick={() => put(route('crm.affiliations.update', affiliation.id), { preserveScroll: true, onSuccess: onClose })}
                    >
                        Mettre fin
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** « Interlocuteurs » tab of the entreprise page. */
export function OrganisationContacts({ organisationId, affiliations, former, decisionRoles, can }: Props) {
    const [dialog, setDialog] = useState<{ editing: AffiliationRow | null } | null>(null);
    const [ending, setEnding] = useState<AffiliationRow | null>(null);

    const subtitle = (a: AffiliationRow) => [a.job_title, a.decision_role?.label].filter(Boolean).join(' · ');

    const actions = (a: AffiliationRow) =>
        can.affiliate && (
            <div className="flex gap-2">
                <Button size="sm" variant="outline" onClick={() => setDialog({ editing: a })}>
                    Modifier
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setEnding(a)}>
                    Mettre fin
                </Button>
            </div>
        );

    const personLink = (a: AffiliationRow) => (
        <Link href={route('crm.people.show', a.person.id)} className="font-medium hover:underline">
            {a.person.civility ? `${a.person.civility} ` : ''}
            {a.person.name}
        </Link>
    );

    return (
        <div role="tabpanel" id="panel-Interlocuteurs" aria-labelledby="tab-Interlocuteurs" className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">
                    Interlocuteurs actifs <span className="text-muted-foreground font-normal">({affiliations.length})</span>
                </h2>
                {can.affiliate && (
                    <Button onClick={() => setDialog({ editing: null })}>
                        <Plus className="h-4 w-4" />
                        Ajouter un interlocuteur
                    </Button>
                )}
            </div>

            {affiliations.length === 0 ? (
                <p className="text-muted-foreground rounded-xl border p-8 text-center text-sm">Aucun interlocuteur pour le moment.</p>
            ) : (
                <>
                    {/* Table from 768 px */}
                    <div className="hidden overflow-x-auto rounded-xl border md:block">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-4 py-2 font-medium">Nom</th>
                                    <th className="px-4 py-2 font-medium">Fonction</th>
                                    <th className="px-4 py-2 font-medium">Rôle dans la décision</th>
                                    <th className="px-4 py-2 font-medium">Coordonnées</th>
                                    <th className="px-4 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {affiliations.map((a) => (
                                    <tr key={a.id} className="border-t align-top">
                                        <td className="px-4 py-2">
                                            {personLink(a)} {a.is_referent && <Badge className="ml-1">Référent</Badge>}
                                        </td>
                                        <td className="px-4 py-2">{a.job_title ?? '—'}</td>
                                        <td className="px-4 py-2">{a.decision_role?.label ?? '—'}</td>
                                        <td className="px-4 py-2">
                                            <ContactLinks email={a.work_email} phone={a.work_phone} />
                                        </td>
                                        <td className="px-4 py-2">{actions(a)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Cards below 768 px */}
                    <ul className="grid gap-3 md:hidden">
                        {affiliations.map((a) => (
                            <li key={a.id} className="rounded-xl border p-4">
                                <div className="flex items-start justify-between gap-2">
                                    {personLink(a)}
                                    {a.is_referent && <Badge>Référent</Badge>}
                                </div>
                                {subtitle(a) && <p className="text-muted-foreground mt-1 text-sm">{subtitle(a)}</p>}
                                <div className="mt-2 text-sm">
                                    <ContactLinks email={a.work_email} phone={a.work_phone} />
                                </div>
                                <div className="mt-3">{actions(a)}</div>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            {former.length > 0 && (
                <details className="rounded-xl border p-4">
                    <summary className="cursor-pointer text-sm font-medium">Anciens interlocuteurs ({former.length})</summary>
                    <ul className="mt-3 grid gap-2 text-sm">
                        {former.map((a) => (
                            <li key={a.id} className="flex flex-wrap items-center justify-between gap-2">
                                <span>
                                    {personLink(a)}
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {[a.job_title, a.started_on && formatDate(a.started_on), a.ended_on && `→ ${formatDate(a.ended_on)}`].filter(Boolean).join(' · ')}
                                    </span>
                                </span>
                                {can.affiliate && (
                                    <Button size="sm" variant="ghost" onClick={() => setDialog({ editing: a })}>
                                        Modifier
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            {dialog && (
                <AffiliationDialog
                    key={dialog.editing?.id ?? 'new'}
                    organisationId={organisationId}
                    editing={dialog.editing}
                    decisionRoles={decisionRoles}
                    canCreatePerson={can.createPerson}
                    onClose={() => setDialog(null)}
                />
            )}
            {ending && <EndDialog affiliation={ending} onClose={() => setEnding(null)} />}
        </div>
    );
}
