import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Absences = 'not_billed' | 'bill_contractor';

interface FinanceSettingsProps {
    settings: { unjustified_absences: Absences };
    reminders: { days: number[]; auto: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Réglages de facturation', href: '/finance/settings' }];

const CHOICES: { value: Absences; label: string; help: string }[] = [
    { value: 'not_billed', label: 'Non facturées', help: 'Les heures manquées ne sont facturées à personne.' },
    {
        value: 'bill_contractor',
        label: 'Facturées au contractant',
        help: 'Ligne distincte « Absences non justifiées - non imputables sur les fonds de la formation professionnelle », au tarif horaire de la ligne ; jamais aux financeurs.',
    },
];

function RemindersForm({ reminders }: { reminders: FinanceSettingsProps['reminders'] }) {
    const initial = [0, 1, 2].map((i) => (reminders.days[i] === undefined ? '' : String(reminders.days[i])));
    const { data, setData, put, processing, errors } = useForm({ days: initial, auto: reminders.auto });
    const errs = errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('finance.settings.reminders'), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex max-w-xl flex-col gap-4">
            <fieldset className="grid gap-3">
                <legend className="mb-1 text-sm font-medium">Relances des factures impayées</legend>
                <p className="text-muted-foreground text-sm">
                    Délais en jours après l'échéance, de 1 à 3 niveaux (laissez un niveau vide pour ne pas l'utiliser). Chaque niveau part une fois le
                    précédent envoyé.
                </p>
                <div className="flex flex-wrap gap-3">
                    {[0, 1, 2].map((i) => (
                        <label key={i} className="grid gap-1 text-sm">
                            Niveau {i + 1} (jours)
                            <input
                                type="number"
                                min={1}
                                max={365}
                                className="border-input bg-background h-9 w-28 rounded-md border px-2"
                                value={data.days[i]}
                                onChange={(e) =>
                                    setData(
                                        'days',
                                        data.days.map((d, j) => (j === i ? e.target.value : d)),
                                    )
                                }
                            />
                        </label>
                    ))}
                </div>
                <InputError message={errs.days ?? errs['days.0'] ?? errs['days.1'] ?? errs['days.2']} />
                <label className="flex items-start gap-3 text-sm">
                    <input type="checkbox" className="mt-1" checked={data.auto} onChange={(e) => setData('auto', e.target.checked)} />
                    <span>
                        <span className="font-medium">Relancer automatiquement</span>
                        <span className="text-muted-foreground block">
                            Chaque matin à 8 h 30, le niveau dû de chaque facture part avec son modèle d'e-mail (Administration › Modèles d'e-mails).
                            Sinon, les relances s'envoient depuis Finance › À relancer.
                        </span>
                    </span>
                </label>
            </fieldset>
            <div>
                <Button type="submit" disabled={processing}>
                    Enregistrer les relances
                </Button>
            </div>
        </form>
    );
}

export default function FinanceSettings({ settings, reminders, status }: FinanceSettingsProps) {
    const { data, setData, put, processing, errors } = useForm({ unjustified_absences: settings.unjustified_absences });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('finance.settings.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Réglages de facturation" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Réglages de facturation"
                    description="Règles appliquées à tous les dossiers ; chaque dossier peut les remplacer par les siennes."
                />

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <form onSubmit={submit} className="flex max-w-xl flex-col gap-6">
                    <fieldset className="grid gap-3">
                        <legend className="mb-1 text-sm font-medium">Absences non justifiées</legend>
                        <p className="text-muted-foreground text-sm">
                            Présences « absent » et part manquante d'un « partiel » des séances passées ; jamais une absence justifiée, une séance
                            annulée ni un stagiaire sorti (règle d'abandon).
                        </p>
                        {CHOICES.map((choice) => (
                            <label key={choice.value} className="flex items-start gap-3 text-sm">
                                <input
                                    type="radio"
                                    name="unjustified_absences"
                                    className="mt-1"
                                    checked={data.unjustified_absences === choice.value}
                                    onChange={() => setData('unjustified_absences', choice.value)}
                                />
                                <span>
                                    <span className="font-medium">{choice.label}</span>
                                    <span className="text-muted-foreground block">{choice.help}</span>
                                </span>
                            </label>
                        ))}
                        <InputError message={errors.unjustified_absences} />
                    </fieldset>

                    <div>
                        <Button type="submit" disabled={processing}>
                            Enregistrer
                        </Button>
                    </div>
                </form>

                <RemindersForm reminders={reminders} />
            </div>
        </AppLayout>
    );
}
