import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface AbandonRuleProps {
    rule: { mode: 'prorata' | 'prorata_dedit'; dedit_percent: number | null };
    trainer_follow_up_months: number;
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: "Règle d'abandon", href: '/admin/abandon-rule' }];

export default function AbandonRule({ rule, trainer_follow_up_months, status }: AbandonRuleProps) {
    const { data, setData, put, processing, errors } = useForm({
        mode: rule.mode,
        dedit_percent: rule.dedit_percent === null ? '' : String(rule.dedit_percent),
    });

    const followUp = useForm({ months: String(trainer_follow_up_months) });
    const submitFollowUp: FormEventHandler = (e) => {
        e.preventDefault();
        followUp.put(route('admin.abandon-rule.trainer-follow-up'), { preserveScroll: true });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.abandon-rule.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Règle d'abandon" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Règle d'abandon"
                    description="Règle appliquée quand un stagiaire quitte une formation avant la fin. Chaque dossier peut la remplacer par la sienne."
                />

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <form onSubmit={submit} className="flex max-w-xl flex-col gap-6">
                    <fieldset className="grid gap-3">
                        <legend className="mb-1 text-sm font-medium">Règle en cas d'abandon</legend>
                        <label className="flex items-start gap-3 text-sm">
                            <input
                                type="radio"
                                name="mode"
                                className="mt-1"
                                checked={data.mode === 'prorata'}
                                onChange={() => setData('mode', 'prorata')}
                            />
                            <span>
                                <span className="font-medium">Prorata seulement</span>
                                <span className="text-muted-foreground block">Le stagiaire est facturé des heures suivies.</span>
                            </span>
                        </label>
                        <label className="flex items-start gap-3 text-sm">
                            <input
                                type="radio"
                                name="mode"
                                className="mt-1"
                                checked={data.mode === 'prorata_dedit'}
                                onChange={() => setData('mode', 'prorata_dedit')}
                            />
                            <span>
                                <span className="font-medium">Prorata et dédit</span>
                                <span className="text-muted-foreground block">
                                    Le prorata, plus un dédit en pourcentage. Un motif d'abandon de force majeure n'entraîne jamais de dédit.
                                </span>
                            </span>
                        </label>
                        <InputError message={errors.mode} />
                    </fieldset>

                    {data.mode === 'prorata_dedit' && (
                        <div className="grid gap-2">
                            <Label htmlFor="dedit_percent">Dédit (%)</Label>
                            <Input
                                id="dedit_percent"
                                type="number"
                                min={1}
                                max={100}
                                step={1}
                                className="w-40"
                                value={data.dedit_percent}
                                onChange={(e) => setData('dedit_percent', e.target.value)}
                            />
                            <p className="text-muted-foreground text-sm">Entre 1 et 100.</p>
                            <InputError message={errors.dedit_percent} />
                        </div>
                    )}

                    <div>
                        <Button type="submit" disabled={processing}>
                            Enregistrer
                        </Button>
                    </div>
                </form>

                <Heading
                    title="Suivi des apprenants"
                    description="Le formateur rappelle chaque apprenant après la fin de sa formation : une action « Appel » lui est créée automatiquement."
                />
                <form onSubmit={submitFollowUp} className="flex max-w-xl flex-col gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="follow_up_months">Délai du suivi formateur (mois après la fin, 0 = aucun suivi)</Label>
                        <Input
                            id="follow_up_months"
                            type="number"
                            min={0}
                            max={36}
                            step={1}
                            className="w-40"
                            value={followUp.data.months}
                            onChange={(e) => followUp.setData('months', e.target.value)}
                        />
                        <InputError message={followUp.errors.months} />
                    </div>
                    <div>
                        <Button type="submit" disabled={followUp.processing}>
                            Enregistrer le délai
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
