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
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Réglages de facturation', href: '/finance/settings' }];

const CHOICES: { value: Absences; label: string; help: string }[] = [
    { value: 'not_billed', label: 'Non facturées', help: 'Les heures manquées ne sont facturées à personne.' },
    {
        value: 'bill_contractor',
        label: 'Facturées au contractant',
        help: 'Ligne distincte « Absences non justifiées — non imputables sur les fonds de la formation professionnelle », au tarif horaire de la ligne ; jamais aux financeurs.',
    },
];

export default function FinanceSettings({ settings, status }: FinanceSettingsProps) {
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
            </div>
        </AppLayout>
    );
}
