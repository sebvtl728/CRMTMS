import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/crm';
import { formatCents } from '@/lib/revenue';
import { Link, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

type Rhythm = 'end' | 'monthly' | 'schedule';
type Basis = 'realised' | 'planned';
type Absences = 'not_billed' | 'bill_contractor';

interface Instalment {
    due_on: string;
    percent: number;
}

/** Props of the « Facturation » block (Finance\Plans\BillingBlock). */
export interface BillingBlockProps {
    plan: {
        rhythm: Rhythm;
        basis: Basis;
        /** null: the organisme's rule */
        unjustified_absences: Absences | null;
        /** the rule applied */
        absences: Absences;
        schedule: Instalment[];
        /** a validated invoice: rhythm, basis and schedule no longer change */
        locked: boolean;
    };
    organisme_absences: Absences;
    /** first day of the dossier's sessions (no instalment before it) */
    starts_on: string | null;
    can_manage: boolean;
    update_url: string;
    /** Fundings paying directly the organisme, each invoiced on its own. */
    direct_fundings: { id: string; funder: string; amount_cents: number; status: string; agreement_number: string | null }[];
    /** What is due now (« À facturer »), read apart: { due, soon } (null: a dossier in the trash). */
    due_url: string | null;
    /** A draft made by hand (null: not allowed). */
    create_url: string | null;
    recipients: { value: string; label: string }[];
}

const RHYTHMS: { value: Rhythm; label: string; help: string }[] = [
    { value: 'end', label: 'À la fin de la formation', help: 'Une facture quand toutes les sessions sont terminées et leurs mois clôturés.' },
    { value: 'monthly', label: 'Mensuel au réalisé', help: 'Une facture par mois de présences clôturé.' },
    { value: 'schedule', label: 'Échéancier', help: 'Des échéances en pourcentage du prix ; la dernière régularise au réalisé.' },
];

const BASES: { value: Basis; label: string }[] = [
    { value: 'realised', label: 'Au réalisé (heures faites)' },
    { value: 'planned', label: 'Au prévu (forfait)' },
];

const ABSENCES: Record<Absences, string> = {
    not_billed: 'Non facturées',
    bill_contractor: 'Facturées au contractant',
};

type PlanForm = {
    rhythm: Rhythm;
    basis: Basis;
    unjustified_absences: Absences | '';
    schedule: { due_on: string; percent: string }[];
};

/**
 * The billing settings of a dossier (rhythm, basis, unjustified absences,
 * schedule), editable by who manages its invoicing; read only otherwise and,
 * for rhythm, basis and schedule, once an invoice is validated.
 */
export function BillingBlock(props: BillingBlockProps) {
    return (
        <div className="grid gap-3">
            <PlanForm {...props} />
            <BillingSummary {...props} />
        </div>
    );
}

/** Fundings paying directly, what is left to invoice, and a draft made by hand. */
interface DueSummary {
    due: { recipient: string; kind: 'invoice' | 'credit_note'; total_cents: number; period: string; draft_url: string | null }[];
    /** Why nothing is due yet (« Formation non terminée »…). */
    soon: string | null;
}

function BillingSummary({ direct_fundings, due_url, create_url, recipients }: BillingBlockProps) {
    const [recipient, setRecipient] = useState(recipients[0]?.value ?? 'contractor');
    const [busy, setBusy] = useState(false);
    const [summary, setSummary] = useState<DueSummary | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!due_url) return;
        let live = true;
        fetch(due_url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? (response.json() as Promise<DueSummary>) : Promise.reject(new Error(String(response.status)))))
            .then((json) => live && setSummary(json))
            .catch(() => live && setFailed(true));

        return () => {
            live = false;
        };
    }, [due_url]);
    const due = summary?.due ?? [];
    const soon = summary?.soon ?? null;

    return (
        <div className="grid gap-3 rounded-xl border p-4 text-sm">
            {direct_fundings.length > 0 && (
                <div>
                    <p className="font-medium">Financements payés directement</p>
                    <ul className="text-muted-foreground">
                        {direct_fundings.map((f) => (
                            <li key={f.id}>
                                {f.funder} : {formatCents(f.amount_cents)}
                                {f.agreement_number ? ` · accord ${f.agreement_number}` : " · sans numéro d'accord"}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            <div>
                <p className="font-medium">Reste à facturer</p>
                {!due_url ? (
                    <p className="text-muted-foreground">Dossier à la corbeille.</p>
                ) : failed ? (
                    <p className="text-muted-foreground">Calcul indisponible pour le moment.</p>
                ) : summary === null ? (
                    <p className="text-muted-foreground">Calcul en cours…</p>
                ) : due.length === 0 ? (
                    <p className="text-muted-foreground">
                        {soon ? `Rien pour le moment : ${soon.toLowerCase()}.` : 'Rien à facturer pour le moment.'}
                    </p>
                ) : (
                    <ul>
                        {due.map((d, i) => (
                            <li key={i}>
                                {d.kind === 'credit_note' ? 'Avoir à faire' : 'À facturer'} à {d.recipient} : {formatCents(d.total_cents)} ({d.period}
                                )
                                {d.draft_url && (
                                    <>
                                        {' · '}
                                        <Link href={d.draft_url} className="underline">
                                            brouillon en cours
                                        </Link>
                                    </>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {create_url && (
                <div className="flex flex-wrap items-end gap-2">
                    <div className="grid gap-1">
                        <Label htmlFor="invoice-recipient" className="text-xs">
                            Destinataire
                        </Label>
                        <select
                            id="invoice-recipient"
                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            value={recipient}
                            onChange={(e) => setRecipient(e.target.value)}
                        >
                            {recipients.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={() => router.post(create_url, { recipient }, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                    >
                        Préparer une facture
                    </Button>
                </div>
            )}
        </div>
    );
}

function PlanForm({ plan, organisme_absences, starts_on, can_manage, update_url }: BillingBlockProps) {
    const { data, setData, put, processing, errors, isDirty } = useForm<PlanForm>({
        rhythm: plan.rhythm,
        basis: plan.basis,
        unjustified_absences: plan.unjustified_absences ?? '',
        schedule: plan.schedule.map((i) => ({ due_on: i.due_on, percent: String(i.percent) })),
    });
    const err = errors as Record<string, string | undefined>;
    const frozen = !can_manage || plan.locked;
    const total = data.schedule.reduce((sum, i) => sum + (Number.parseInt(i.percent, 10) || 0), 0);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(update_url, { preserveScroll: true });
    };

    const setInstalment = (index: number, key: 'due_on' | 'percent', value: string) =>
        setData(
            'schedule',
            data.schedule.map((i, j) => (j === index ? { ...i, [key]: value } : i)),
        );

    const chooseRhythm = (rhythm: Rhythm) => {
        setData((d) => ({
            ...d,
            rhythm,
            // The monthly rhythm is billed on the realised hours only.
            basis: rhythm === 'monthly' ? 'realised' : d.basis,
            // A first schedule: two instalments to adjust.
            schedule:
                rhythm === 'schedule' && d.schedule.length === 0
                    ? [
                          { due_on: starts_on ?? '', percent: '30' },
                          { due_on: '', percent: '70' },
                      ]
                    : d.schedule,
        }));
    };

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-xl border p-4">
            {plan.locked && (
                <p className="text-muted-foreground text-sm">
                    Une facture de ce dossier est validée : le rythme, la base et l'échéancier ne changent plus (faites d'abord un avoir).
                </p>
            )}

            <fieldset className="grid gap-2" disabled={frozen}>
                <legend className="mb-1 text-sm font-medium">Rythme de facturation</legend>
                {RHYTHMS.map((r) => (
                    <label key={r.value} className="flex items-start gap-3 text-sm">
                        <input type="radio" name="rhythm" className="mt-1" checked={data.rhythm === r.value} onChange={() => chooseRhythm(r.value)} />
                        <span>
                            <span className="font-medium">{r.label}</span>
                            <span className="text-muted-foreground block">{r.help}</span>
                        </span>
                    </label>
                ))}
                <InputError message={err.rhythm} />
            </fieldset>

            <fieldset className="grid gap-2" disabled={frozen}>
                <legend className="mb-1 text-sm font-medium">Base</legend>
                <div className="flex flex-wrap gap-4">
                    {BASES.map((b) => (
                        <label key={b.value} className="flex items-center gap-2 text-sm">
                            <input
                                type="radio"
                                name="basis"
                                checked={data.basis === b.value}
                                disabled={data.rhythm === 'monthly' && b.value !== 'realised'}
                                onChange={() => setData('basis', b.value)}
                            />
                            {b.label}
                        </label>
                    ))}
                </div>
                {data.rhythm === 'monthly' && <p className="text-muted-foreground text-sm">Le rythme mensuel se facture toujours au réalisé.</p>}
                <InputError message={err.basis} />
            </fieldset>

            {data.rhythm === 'schedule' && (
                <fieldset className="grid gap-2" disabled={frozen}>
                    <legend className="mb-1 text-sm font-medium">Échéances</legend>
                    {starts_on && (
                        <p className="text-muted-foreground text-xs">Pas d'échéance avant le début de la formation ({formatDate(starts_on)}).</p>
                    )}
                    <ul className="grid gap-2">
                        {data.schedule.map((instalment, index) => (
                            <li key={index} className="flex flex-wrap items-start gap-2">
                                <div className="grid gap-1">
                                    <Label htmlFor={`schedule-${index}-due_on`} className="sr-only">
                                        Date de l'échéance {index + 1}
                                    </Label>
                                    <Input
                                        id={`schedule-${index}-due_on`}
                                        type="date"
                                        min={starts_on ?? undefined}
                                        value={instalment.due_on}
                                        onChange={(e) => setInstalment(index, 'due_on', e.target.value)}
                                        className="w-44"
                                    />
                                    <InputError message={err[`schedule.${index}.due_on`]} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor={`schedule-${index}-percent`} className="sr-only">
                                        Pourcentage de l'échéance {index + 1}
                                    </Label>
                                    <div className="flex items-center gap-1">
                                        <Input
                                            id={`schedule-${index}-percent`}
                                            type="number"
                                            min={1}
                                            max={100}
                                            step={1}
                                            value={instalment.percent}
                                            onChange={(e) => setInstalment(index, 'percent', e.target.value)}
                                            className="w-24"
                                        />
                                        <span className="text-sm">%</span>
                                    </div>
                                    <InputError message={err[`schedule.${index}.percent`]} />
                                </div>
                                {!frozen && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Retirer l'échéance ${index + 1}`}
                                        onClick={() =>
                                            setData(
                                                'schedule',
                                                data.schedule.filter((_, j) => j !== index),
                                            )
                                        }
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                    <div className="flex items-center gap-3">
                        {!frozen && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setData('schedule', [...data.schedule, { due_on: '', percent: '' }])}
                            >
                                <Plus className="h-4 w-4" />
                                Ajouter une échéance
                            </Button>
                        )}
                        <span className={`text-sm ${total === 100 ? 'text-muted-foreground' : 'text-destructive'}`}>Total : {total} %</span>
                    </div>
                    <InputError message={err.schedule} />
                </fieldset>
            )}

            <fieldset className="grid gap-2" disabled={!can_manage}>
                <legend className="mb-1 text-sm font-medium">Absences non justifiées</legend>
                <div className="flex flex-wrap gap-4">
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="radio"
                            name="unjustified_absences"
                            checked={data.unjustified_absences === ''}
                            onChange={() => setData('unjustified_absences', '')}
                        />
                        Comme l'organisme : {ABSENCES[organisme_absences].toLowerCase()}
                    </label>
                    {/* Only the other choice, unless the dossier already holds the organisme's one. */}
                    {(Object.keys(ABSENCES) as Absences[])
                        .filter((value) => value !== organisme_absences || plan.unjustified_absences === value)
                        .map((value) => (
                            <label key={value} className="flex items-center gap-2 text-sm">
                                <input
                                    type="radio"
                                    name="unjustified_absences"
                                    checked={data.unjustified_absences === value}
                                    onChange={() => setData('unjustified_absences', value)}
                                />
                                Pour ce dossier : {ABSENCES[value].toLowerCase()}
                            </label>
                        ))}
                </div>
                <p className="text-muted-foreground text-xs">
                    Facturées, elles le sont au seul contractant, sur une ligne distincte, jamais aux financeurs.
                </p>
                <InputError message={err.unjustified_absences} />
            </fieldset>

            {can_manage ? (
                <div>
                    <Button type="submit" disabled={processing || !isDirty}>
                        Enregistrer
                    </Button>
                </div>
            ) : (
                <div className="flex flex-wrap gap-2">
                    <Badge variant="secondary">{RHYTHMS.find((r) => r.value === plan.rhythm)?.label}</Badge>
                    <Badge variant="secondary">{BASES.find((b) => b.value === plan.basis)?.label}</Badge>
                    <Badge variant="secondary">Absences : {ABSENCES[plan.absences].toLowerCase()}</Badge>
                </div>
            )}
        </form>
    );
}
