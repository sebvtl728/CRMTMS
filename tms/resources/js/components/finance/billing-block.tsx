import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/crm';
import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

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
export function BillingBlock({ plan, organisme_absences, starts_on, can_manage, update_url }: BillingBlockProps) {
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
                        Réglage de l'organisme ({ABSENCES[organisme_absences].toLowerCase()})
                    </label>
                    {(Object.keys(ABSENCES) as Absences[]).map((value) => (
                        <label key={value} className="flex items-center gap-2 text-sm">
                            <input
                                type="radio"
                                name="unjustified_absences"
                                checked={data.unjustified_absences === value}
                                onChange={() => setData('unjustified_absences', value)}
                            />
                            {ABSENCES[value]}
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
