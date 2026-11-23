import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { FichePicker } from '@/components/sessions/fiche-picker';
import { emptyPerson, NewPersonFields, newPersonLabel, type NewPerson } from '@/components/sessions/new-person-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDay, type Option } from '@/lib/crm';
import { estimateEndDate, HOURS_PER_DAY, parseHours } from '@/lib/sessions';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { LoaderCircle, Plus, X } from 'lucide-react';
import { useState } from 'react';

type Kind = 'intra' | 'inter' | 'individual';

interface SessionOption {
    id: string;
    number: string;
    title: string | null;
    starts_on: string;
    ends_on: string;
    kind_label: string;
    capacity: number | null;
}

interface PlanLine {
    index: number;
    kind: 'training' | 'fee';
    label: string;
    training: { id: string; title: string; code: string | null; hours: string | null } | null;
    quantity: string;
    unit: string;
    unit_price: string;
    discount_percent: string;
    total_ht: string;
    pricing_mode: string | null;
    sessions: SessionOption[];
}

interface Plan {
    quote: {
        id: string;
        number: string;
        account: { type: 'organisation' | 'person'; id: string; label: string };
        contact: { id: string; label: string } | null;
        das: string | null;
        total_ht: string;
    };
    lines: PlanLine[];
}

interface Props {
    existing: { number: string; url: string | null } | null;
    plan: Plan | null;
    options: {
        kind: Option[];
        modality: Option[];
        default_modality: string | null;
        location: Option[];
        location_companies: Option[];
        pricing_mode: Option[];
    };
    can: { sessions: boolean };
}

interface NewSession {
    title: string;
    kind: Kind;
    starts_on: string;
    ends_on: string;
    modality_id: string;
    location_kind: string;
    location_text: string;
    location_company_id: string;
    capacity: string;
}

/** A person of the CRM, or a person to create (by its ref: one person however many lines list it). */
type Trainee = { key: number; person: { id: string; label: string } } | { key: number; ref: string };

interface LineState {
    choice: 'existing' | 'new' | 'later';
    session_id: string;
    new_session: NewSession;
    pricing_mode: string;
    trainees: Trainee[];
}

const STEPS = ['Récapitulatif', 'Sessions', 'Stagiaires'] as const;

let nextKey = 1;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function sessionLabel(s: SessionOption): string {
    const dates = s.starts_on === s.ends_on ? formatDay(s.starts_on) : `${formatDay(s.starts_on)} au ${formatDay(s.ends_on)}`;

    return `${s.title ? `${s.title} · ` : ''}${s.number} · ${s.kind_label} · ${dates}${s.capacity ? ` · ${s.capacity} places` : ''}`;
}

function traineeLabel(t: Trainee, people: Record<string, NewPerson>): string {
    return 'person' in t ? t.person.label : newPersonLabel(people[t.ref]);
}

/** A failure answered without the errors of the plan: a French message, whatever the server said. */
function failure(status: number): string {
    switch (status) {
        case 419:
            return 'Session expirée, rechargez la page.';
        case 404:
            return 'Ce devis est introuvable ou n’est plus accepté : rechargez la page.';
        case 403:
            return 'Vous n’avez pas le droit de créer ce dossier, ses sessions ou ses inscriptions (droits ou DAS du devis).';
        case 429:
            return 'Trop de requêtes, réessayez dans quelques instants.';
        default:
            return 'La création a échoué, réessayez.';
    }
}

/** The step of the assistant where an error of the plan is corrected. */
function stepOf(key: string): number {
    if (/^lines\.\d+\.trainees/.test(key)) {
        return 2;
    }

    return /^lines\.\d+\./.test(key) ? 1 : 0;
}

export default function DossierFromQuote({ existing, plan, options, can }: Props) {
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [conflict, setConflict] = useState<{ number: string; url: string | null } | null>(existing);
    const account = plan?.quote.account;
    const [lines, setLines] = useState<LineState[]>(() =>
        (plan?.lines ?? []).map((l) => ({
            choice: !can.sessions ? 'later' : l.sessions.length > 0 ? 'existing' : 'new',
            session_id: '',
            new_session: {
                title: '',
                kind: account?.type === 'organisation' ? 'intra' : 'individual',
                starts_on: '',
                ends_on: '',
                modality_id: options.default_modality ?? '',
                location_kind: 'client',
                location_text: '',
                location_company_id: options.location_companies[0]?.value ?? '',
                capacity: '',
            },
            pricing_mode: l.pricing_mode ?? '',
            trainees: [],
        })),
    );
    const [drafts, setDrafts] = useState<Record<number, NewPerson | null>>({});
    const [newPeople, setNewPeople] = useState<Record<string, NewPerson>>({});
    const [pickerKeys, setPickerKeys] = useState<Record<number, number>>({});

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dossiers', href: route('dossiers.index') },
        { title: plan ? `Créer le dossier du devis ${plan.quote.number}` : 'Créer le dossier', href: '#' },
    ];

    if (!plan || conflict) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Créer le dossier" />
                <div className="flex max-w-3xl flex-col gap-4 p-4">
                    <Heading title="Créer le dossier" />
                    <div role="alert" className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-700 dark:bg-amber-950">
                        <p className="font-medium">Un dossier existe déjà pour ce devis.</p>
                        {conflict?.url ? (
                            <Link href={conflict.url} className="underline">
                                Voir le dossier {conflict.number}
                            </Link>
                        ) : (
                            conflict && <p>Dossier {conflict.number}.</p>
                        )}
                    </div>
                </div>
            </AppLayout>
        );
    }

    const update = (i: number, change: Partial<LineState>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...change } : l)));
    const updateSession = (i: number, change: Partial<NewSession>) =>
        setLines((ls) => ls.map((l, j) => (j === i ? { ...l, new_session: { ...l.new_session, ...change } } : l)));
    const updatePerson = (ref: string, change: Partial<NewPerson>) => setNewPeople((p) => ({ ...p, [ref]: { ...p[ref], ...change } }));
    const err = (key: string) => errors[key];
    const training = plan.lines.filter((l) => l.kind === 'training');
    const withSession = lines.map((l, i) => plan.lines[i].kind === 'training' && l.choice !== 'later');

    /** Step 2 → 3: a session chosen where « Session existante » is ticked. */
    const checkSessions = (): boolean => {
        const missing: Record<string, string> = {};
        lines.forEach((l, i) => {
            if (plan.lines[i].kind === 'training' && l.choice === 'existing' && l.session_id === '') {
                missing[`lines.${i}.session_id`] = 'Choisissez la session.';
            }
        });
        setErrors(missing);

        return Object.keys(missing).length === 0;
    };

    const payload = () => ({
        lines: lines.map((l, i) => {
            if (plan.lines[i].kind === 'fee') {
                return {};
            }
            const session = l.choice === 'existing' ? { session_id: l.session_id } : l.choice === 'new' ? { new_session: l.new_session } : {};

            return {
                pricing_mode: l.pricing_mode,
                ...session,
                trainees:
                    l.choice === 'later'
                        ? []
                        : l.trainees.map((t) => ('person' in t ? t.person.id : { new_person: { ...newPeople[t.ref], ref: t.ref } })),
            };
        }),
    });

    const submit = async () => {
        setBusy(true);
        setErrors({});
        try {
            const response = await fetch(route('dossiers.from-quote.store', plan.quote.id), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify(payload()),
            });
            const json = (await response.json().catch(() => ({}))) as {
                url?: string;
                errors?: Record<string, string[]>;
                message?: string;
                dossier?: { number: string; url: string | null };
            };
            if (response.status === 201 && json.url) {
                router.visit(json.url);

                return;
            }
            if (response.status === 409 && json.dossier) {
                setConflict(json.dossier);
            } else if (response.status === 422 && json.errors) {
                const flat = Object.fromEntries(Object.entries(json.errors).map(([k, v]) => [k, v[0]]));
                setErrors(flat);
                setStep(Math.min(...Object.keys(flat).map(stepOf)));
            } else {
                setErrors({ form: failure(response.status) });
            }
        } catch {
            setErrors({ form: 'La création a échoué, réessayez.' });
        } finally {
            setBusy(false);
        }
    };

    const where = (key: string): string => {
        const m = /^lines\.(\d+)\.(?:trainees\.(\d+))?/.exec(key);
        const line = m ? plan.lines[Number(m[1])] : undefined;
        const trainee = m && m[2] !== undefined ? lines[Number(m[1])]?.trainees[Number(m[2])] : undefined;

        return line ? `${line.label}${trainee ? `, ${traineeLabel(trainee, newPeople)}` : ''} : ` : '';
    };
    const messages = Object.entries(errors);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Créer le dossier du devis ${plan.quote.number}`} />

            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <Heading title={`Créer le dossier du devis ${plan.quote.number}`} />

                <ol className="flex flex-wrap gap-2 text-sm" aria-label="Étapes">
                    {STEPS.map((label, i) => (
                        <li
                            key={label}
                            aria-current={i === step ? 'step' : undefined}
                            className={`rounded-full border px-3 py-1 ${i === step ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'}`}
                        >
                            {i + 1}. {label}
                        </li>
                    ))}
                </ol>

                {messages.length > 0 && (
                    <div role="alert" className="rounded-xl border border-red-300 p-4 text-sm text-red-700 dark:border-red-800 dark:text-red-300">
                        <p className="font-medium">Le dossier n’a pas été créé :</p>
                        <ul className="list-disc pl-5">
                            {messages.map(([key, message]) => (
                                <li key={key}>
                                    {where(key)}
                                    {message}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {step === 0 && (
                    <section className="flex flex-col gap-4" aria-label="Récapitulatif">
                        <dl className="grid gap-4 rounded-xl border p-4 text-sm md:grid-cols-4">
                            <div>
                                <dt className="text-muted-foreground text-xs">
                                    {plan.quote.account.type === 'organisation' ? 'Entreprise' : 'Particulier'}
                                </dt>
                                <dd>{plan.quote.account.label}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Contact</dt>
                                <dd>{plan.quote.contact?.label ?? '-'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">DAS</dt>
                                <dd>{plan.quote.das ?? '-'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Montant HT</dt>
                                <dd className="font-medium">{plan.quote.total_ht}</dd>
                            </div>
                        </dl>
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="p-2">Ligne</th>
                                        <th className="p-2">Type</th>
                                        <th className="p-2 text-right">Quantité</th>
                                        <th className="p-2 text-right">Prix unitaire HT</th>
                                        <th className="p-2 text-right">Remise</th>
                                        <th className="p-2 text-right">Total HT</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {plan.lines.map((l) => (
                                        <tr key={l.index} className="border-t">
                                            <td className="p-2">{l.label}</td>
                                            <td className="p-2">{l.kind === 'training' ? 'Formation' : 'Frais'}</td>
                                            <td className="p-2 text-right">
                                                {l.quantity} {l.unit}
                                            </td>
                                            <td className="p-2 text-right">{l.unit_price}</td>
                                            <td className="p-2 text-right">{l.discount_percent} %</td>
                                            <td className="p-2 text-right">{l.total_ht}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="text-muted-foreground text-sm">
                            Le dossier reprend le contractant, le contact et les lignes du devis ; un financement «{' '}
                            {plan.quote.account.type === 'organisation' ? 'Entreprise' : 'Personnel'} » du montant total, à demander, est ajouté.
                        </p>
                    </section>
                )}

                {step === 1 && (
                    <section className="flex flex-col gap-4" aria-label="Sessions">
                        {training.length === 0 && <p className="text-sm">Ce devis n’a aucune ligne de formation : rien à planifier.</p>}
                        {!can.sessions && training.length > 0 && (
                            <p className="text-muted-foreground text-sm">
                                Vous n’avez pas le droit de planifier des sessions : le dossier est créé sans session, les stagiaires s’inscriront
                                depuis le dossier.
                            </p>
                        )}
                        {plan.lines.map((l, i) =>
                            l.kind === 'fee' ? null : (
                                <fieldset key={l.index} className="grid gap-4 rounded-xl border p-4">
                                    <legend className="px-2 text-sm font-semibold">{l.label}</legend>
                                    <div className="grid gap-2 md:max-w-xs">
                                        <Label htmlFor={`pricing_${i}`}>Mode de prix</Label>
                                        <Select value={lines[i].pricing_mode} onValueChange={(v) => update(i, { pricing_mode: v })}>
                                            <SelectTrigger id={`pricing_${i}`}>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {options.pricing_mode.map((o) => (
                                                    <SelectItem key={o.value} value={o.value}>
                                                        {o.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={err(`lines.${i}.pricing_mode`)} />
                                    </div>
                                    {can.sessions && (
                                        <>
                                            <fieldset className="grid gap-2">
                                                <legend className="mb-1 text-sm font-medium">Session</legend>
                                                <div className="flex flex-wrap gap-6 text-sm">
                                                    {(
                                                        [
                                                            ['existing', 'Session existante'],
                                                            ['new', 'Nouvelle session'],
                                                            ['later', 'Plus tard'],
                                                        ] as const
                                                    ).map(([value, label]) => (
                                                        <label key={value} className="flex items-center gap-2">
                                                            <input
                                                                type="radio"
                                                                name={`choice_${i}`}
                                                                checked={lines[i].choice === value}
                                                                disabled={value === 'existing' && l.sessions.length === 0}
                                                                onChange={() => update(i, { choice: value })}
                                                            />
                                                            {label}
                                                        </label>
                                                    ))}
                                                </div>
                                                {l.sessions.length === 0 && (
                                                    <p className="text-muted-foreground text-xs">
                                                        Aucune session de cette formation n’est ouverte à ce dossier.
                                                    </p>
                                                )}
                                            </fieldset>
                                            {lines[i].choice === 'existing' && (
                                                <fieldset className="grid gap-1">
                                                    <legend className="mb-1 text-sm font-medium">Sessions de « {l.training?.title} »</legend>
                                                    {l.sessions.map((s) => (
                                                        <label key={s.id} className="flex items-center gap-2 text-sm">
                                                            <input
                                                                type="radio"
                                                                name={`session_${i}`}
                                                                value={s.id}
                                                                checked={lines[i].session_id === s.id}
                                                                onChange={() => update(i, { session_id: s.id })}
                                                            />
                                                            {sessionLabel(s)}
                                                        </label>
                                                    ))}
                                                    <InputError message={err(`lines.${i}.session_id`)} />
                                                </fieldset>
                                            )}
                                            {lines[i].choice === 'new' && (
                                                <NewSessionFields
                                                    index={i}
                                                    value={lines[i].new_session}
                                                    onChange={(change) => updateSession(i, change)}
                                                    accountLabel={plan.quote.account.type === 'organisation' ? plan.quote.account.label : null}
                                                    options={options}
                                                    error={(field) => err(`lines.${i}.new_session.${field}`)}
                                                    hours={plan.lines[i].training?.hours ?? null}
                                                />
                                            )}
                                            {lines[i].choice !== 'existing' && <InputError message={err(`lines.${i}.session_id`)} />}
                                        </>
                                    )}
                                </fieldset>
                            ),
                        )}
                    </section>
                )}

                {step === 2 && (
                    <section className="flex flex-col gap-4" aria-label="Stagiaires">
                        {!withSession.some(Boolean) && (
                            <p className="text-sm">Aucune session choisie : les stagiaires s’inscriront plus tard, depuis le dossier.</p>
                        )}
                        {plan.lines.map((l, i) => {
                            if (!withSession[i]) {
                                return null;
                            }
                            const draft = drafts[i] ?? null;

                            return (
                                <fieldset key={l.index} className="grid gap-3 rounded-xl border p-4">
                                    <legend className="px-2 text-sm font-semibold">{l.label}</legend>
                                    {lines[i].trainees.length > 0 && (
                                        <ul className="grid gap-2" aria-label={`Stagiaires de ${l.label}`}>
                                            {lines[i].trainees.map((t, j) => (
                                                <li key={t.key} className="grid gap-1 rounded-md border px-3 py-2 text-sm">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span>{traineeLabel(t, newPeople)}</span>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            aria-label={`Retirer ${traineeLabel(t, newPeople)}`}
                                                            onClick={() => update(i, { trainees: lines[i].trainees.filter((x) => x.key !== t.key) })}
                                                        >
                                                            <X className="h-4 w-4" />
                                                        </Button>
                                                    </div>
                                                    {Object.entries(errors)
                                                        .filter(
                                                            ([k]) => k === `lines.${i}.trainees.${j}` || k.startsWith(`lines.${i}.trainees.${j}.`),
                                                        )
                                                        .map(([k, m]) => (
                                                            <InputError key={k} message={m} />
                                                        ))}
                                                    {'ref' in t && err(`lines.${i}.trainees.${j}`)?.startsWith('Des personnes similaires') && (
                                                        <label className="flex items-center gap-2 text-xs">
                                                            <input
                                                                type="checkbox"
                                                                checked={newPeople[t.ref]?.confirm_duplicate ?? false}
                                                                onChange={(e) => updatePerson(t.ref, { confirm_duplicate: e.target.checked })}
                                                            />
                                                            Créer quand même
                                                        </label>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    <InputError message={err(`lines.${i}.trainees`)} />
                                    <div className="grid gap-1 md:max-w-lg">
                                        <Label htmlFor={`trainee_search_${i}`}>Ajouter un stagiaire du CRM</Label>
                                        <FichePicker
                                            key={pickerKeys[i] ?? 0}
                                            id={`trainee_search_${i}`}
                                            kind="person"
                                            organisationId={plan.quote.account.type === 'organisation' ? plan.quote.account.id : undefined}
                                            value={null}
                                            onPick={(p) => {
                                                if (p && !lines[i].trainees.some((t) => 'person' in t && t.person.id === p.id)) {
                                                    update(i, { trainees: [...lines[i].trainees, { key: nextKey++, person: p }] });
                                                }
                                                setPickerKeys((k) => ({ ...k, [i]: (k[i] ?? 0) + 1 }));
                                            }}
                                            placeholder="Nom, prénom ou e-mail (2 lettres au moins)"
                                        />
                                    </div>
                                    {Object.keys(newPeople)
                                        .filter((ref) =>
                                            withSession.some((w, k) => w && k !== i && lines[k].trainees.some((t) => 'ref' in t && t.ref === ref)),
                                        )
                                        .filter((ref) => !lines[i].trainees.some((t) => 'ref' in t && t.ref === ref))
                                        .map((ref) => (
                                            <div key={ref}>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => update(i, { trainees: [...lines[i].trainees, { key: nextKey++, ref }] })}
                                                >
                                                    <Plus className="h-4 w-4" />
                                                    Ajouter {newPersonLabel(newPeople[ref])}
                                                </Button>
                                            </div>
                                        ))}
                                    {draft === null ? (
                                        <div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => setDrafts((d) => ({ ...d, [i]: emptyPerson() }))}
                                            >
                                                <Plus className="h-4 w-4" />
                                                Créer un stagiaire
                                            </Button>
                                        </div>
                                    ) : (
                                        <NewPersonFields
                                            index={i}
                                            value={draft}
                                            employer={plan.quote.account.type === 'organisation' ? plan.quote.account.label : null}
                                            onChange={(change) => setDrafts((d) => ({ ...d, [i]: { ...draft, ...change } }))}
                                            onCancel={() => setDrafts((d) => ({ ...d, [i]: null }))}
                                            onAdd={() => {
                                                const ref = `n${nextKey++}`;
                                                setNewPeople((p) => ({ ...p, [ref]: draft }));
                                                update(i, { trainees: [...lines[i].trainees, { key: nextKey++, ref }] });
                                                setDrafts((d) => ({ ...d, [i]: null }));
                                            }}
                                        />
                                    )}
                                </fieldset>
                            );
                        })}
                    </section>
                )}

                <InputError message={err('form')} />
                <div className="flex flex-wrap gap-2">
                    {step > 0 && (
                        <Button type="button" variant="outline" onClick={() => setStep(step - 1)}>
                            Précédent
                        </Button>
                    )}
                    {step < STEPS.length - 1 ? (
                        <Button
                            type="button"
                            onClick={() => {
                                if (step === 1 && !checkSessions()) {
                                    return;
                                }
                                setStep(step + 1);
                            }}
                        >
                            Suivant
                        </Button>
                    ) : (
                        <Button type="button" onClick={submit} disabled={busy}>
                            {busy && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Créer le dossier
                        </Button>
                    )}
                    <Button asChild variant="ghost">
                        <Link href={route('crm.quotes.show', plan.quote.id)}>Annuler</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}

function NewSessionFields({
    index,
    value,
    onChange,
    accountLabel,
    options,
    error,
    hours = null,
}: {
    index: number;
    value: NewSession;
    onChange: (change: Partial<NewSession>) => void;
    accountLabel: string | null;
    options: Props['options'];
    error: (field: string) => string | undefined;
    /** Hours of the training: the end is estimated from them (7 h a working day). */
    hours?: string | null;
}) {
    const kinds = options.kind.filter((k) => k.value !== 'intra' || accountLabel !== null);

    return (
        <div className="grid gap-4 md:grid-cols-3">
            <div className="grid gap-2 md:col-span-3">
                <Label htmlFor={`session_title_${index}`}>Intitulé (facultatif)</Label>
                <Input
                    id={`session_title_${index}`}
                    maxLength={150}
                    value={value.title}
                    onChange={(e) => onChange({ title: e.target.value })}
                    placeholder="Ex. Commis de cuisine – entrée du 30 novembre 2026"
                />
                <InputError message={error('title')} />
            </div>
            <fieldset className="grid gap-2 md:col-span-3">
                <legend className="mb-1 text-sm font-medium">Type *</legend>
                <div className="flex flex-wrap gap-6 text-sm">
                    {kinds.map((k) => (
                        <label key={k.value} className="flex items-center gap-2">
                            <input
                                type="radio"
                                name={`kind_${index}`}
                                checked={value.kind === k.value}
                                onChange={() => onChange({ kind: k.value as Kind })}
                            />
                            {k.value === 'intra' ? `${k.label} (${accountLabel})` : k.label}
                        </label>
                    ))}
                </div>
                <InputError message={error('kind') ?? error('organisation_id') ?? error('training_id')} />
            </fieldset>
            <div className="grid gap-2">
                <Label htmlFor={`starts_on_${index}`}>Début *</Label>
                <Input
                    id={`starts_on_${index}`}
                    type="date"
                    value={value.starts_on}
                    onChange={(e) => {
                        // The end follows the start while it is empty or still the previous estimate.
                        const estimate = estimateEndDate(e.target.value, parseHours(hours)) ?? e.target.value;
                        const previous = estimateEndDate(value.starts_on, parseHours(hours)) ?? value.starts_on;
                        onChange({
                            starts_on: e.target.value,
                            ends_on: value.ends_on === '' || value.ends_on === previous ? estimate : value.ends_on,
                        });
                    }}
                />
                <InputError message={error('starts_on')} />
                {hours && value.starts_on && (
                    <p className="text-muted-foreground text-xs">Fin estimée à {HOURS_PER_DAY} h par jour ouvré (jours fériés exclus) : à ajuster.</p>
                )}
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`ends_on_${index}`}>Fin *</Label>
                <Input id={`ends_on_${index}`} type="date" value={value.ends_on} onChange={(e) => onChange({ ends_on: e.target.value })} />
                <InputError message={error('ends_on')} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`modality_${index}`}>Modalité *</Label>
                <Select value={value.modality_id} onValueChange={(v) => onChange({ modality_id: v })}>
                    <SelectTrigger id={`modality_${index}`}>
                        <SelectValue placeholder="Choisir" />
                    </SelectTrigger>
                    <SelectContent>
                        {options.modality.map((o) => (
                            <SelectItem key={o.value} value={o.value}>
                                {o.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={error('modality_id')} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`location_${index}`}>Lieu *</Label>
                <Select value={value.location_kind} onValueChange={(v) => onChange({ location_kind: v })}>
                    <SelectTrigger id={`location_${index}`}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {options.location.map((o) => (
                            <SelectItem key={o.value} value={o.value}>
                                {o.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={error('location_kind')} />
            </div>
            {value.location_kind === 'company' && (
                <div className="grid gap-2">
                    <Label htmlFor={`location_company_${index}`}>Société *</Label>
                    <Select value={value.location_company_id} onValueChange={(v) => onChange({ location_company_id: v })}>
                        <SelectTrigger id={`location_company_${index}`}>
                            <SelectValue placeholder="Choisir" />
                        </SelectTrigger>
                        <SelectContent>
                            {options.location_companies.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={error('location_company_id')} />
                </div>
            )}
            {value.location_kind === 'address' && (
                <div className="grid gap-2 md:col-span-2">
                    <Label htmlFor={`location_text_${index}`}>Adresse *</Label>
                    <Input id={`location_text_${index}`} value={value.location_text} onChange={(e) => onChange({ location_text: e.target.value })} />
                    <InputError message={error('location_text')} />
                </div>
            )}
            {value.kind === 'inter' && (
                <div className="grid gap-2">
                    <Label htmlFor={`capacity_${index}`}>Nombre de places *</Label>
                    <Input
                        id={`capacity_${index}`}
                        type="number"
                        min={1}
                        max={1000}
                        value={value.capacity}
                        onChange={(e) => onChange({ capacity: e.target.value })}
                    />
                    <InputError message={error('capacity')} />
                </div>
            )}
        </div>
    );
}
