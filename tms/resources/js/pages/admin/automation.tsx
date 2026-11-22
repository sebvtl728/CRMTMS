import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Play } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface AutomationProps {
    settings: { convocation_days: number; day_before: boolean; signature_reminder_days: number; signature_reminder_max: number };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Automatismes', href: '/admin/automation' }];

/** Administration › Automatismes: what leaves by itself every morning at 7:15, and « Exécuter maintenant ». */
export default function Automation({ settings, status }: AutomationProps) {
    const { data, setData, transform, put, processing, errors } = useForm({
        convocation_days: String(settings.convocation_days),
        day_before: settings.day_before,
        signature_reminder_days: String(settings.signature_reminder_days),
        signature_reminder_max: String(settings.signature_reminder_max),
    });
    const [running, setRunning] = useState(false);
    const number = (v: string) => (v === '' ? null : Number(v));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((d) => ({
            convocation_days: number(d.convocation_days),
            day_before: d.day_before,
            signature_reminder_days: number(d.signature_reminder_days),
            signature_reminder_max: number(d.signature_reminder_max),
        }));
        put(route('admin.automation.update'), { preserveScroll: true });
    };

    const runNow = () => {
        setRunning(true);
        router.post(route('admin.automation.run'), {}, { preserveScroll: true, onFinish: () => setRunning(false) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Automatismes" />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Automatismes"
                    description="Ce qui part tout seul chaque matin à 7 h 15 : convocations, rappels de la veille et relances de signature. Les textes se règlent dans les modèles d'e-mails."
                />
                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <form onSubmit={submit} className="flex max-w-xl flex-col gap-6">
                    <section className="grid gap-2 rounded-md border p-4">
                        <h3 className="font-medium">Convocations</h3>
                        <Label htmlFor="convocation_days">Envoyer les convocations (jours avant le début de la session)</Label>
                        <Input
                            id="convocation_days"
                            inputMode="numeric"
                            className="w-24"
                            value={data.convocation_days}
                            onChange={(e) => setData('convocation_days', e.target.value.replace(/\D/g, ''))}
                        />
                        <p className="text-muted-foreground text-xs">
                            Sessions confirmées seulement. Une session confirmée plus tard reçoit ses convocations le lendemain matin ; la veille du
                            début, plus rien ne part. 0 = désactivé.
                        </p>
                        <InputError message={errors.convocation_days} />
                    </section>

                    <section className="grid gap-2 rounded-md border p-4">
                        <h3 className="font-medium">Rappel la veille</h3>
                        <div className="flex items-center gap-2">
                            <Checkbox id="day_before" checked={data.day_before} onCheckedChange={(v) => setData('day_before', v === true)} />
                            <Label htmlFor="day_before">Rappeler la journée du lendemain aux stagiaires et aux formateurs</Label>
                        </div>
                        <p className="text-muted-foreground text-xs">Horaires, module et lieu ; jamais de prix ni la liste des autres stagiaires.</p>
                        <InputError message={errors.day_before} />
                    </section>

                    <section className="grid gap-2 rounded-md border p-4">
                        <h3 className="font-medium">Relance des signatures en attente</h3>
                        <Label htmlFor="signature_reminder_days">Relancer après (jours sans signature)</Label>
                        <Input
                            id="signature_reminder_days"
                            inputMode="numeric"
                            className="w-24"
                            value={data.signature_reminder_days}
                            onChange={(e) => setData('signature_reminder_days', e.target.value.replace(/\D/g, ''))}
                        />
                        <InputError message={errors.signature_reminder_days} />
                        <Label htmlFor="signature_reminder_max">Nombre de relances au plus</Label>
                        <Input
                            id="signature_reminder_max"
                            inputMode="numeric"
                            className="w-24"
                            value={data.signature_reminder_max}
                            onChange={(e) => setData('signature_reminder_max', e.target.value.replace(/\D/g, ''))}
                        />
                        <p className="text-muted-foreground text-xs">
                            Conventions, contrats et devis à signer en ligne. Chaque relance envoie un nouveau lien qui remplace le précédent. 0 jour
                            = désactivé.
                        </p>
                        <InputError message={errors.signature_reminder_max} />
                    </section>

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            Enregistrer
                        </Button>
                        <Button type="button" variant="outline" disabled={running} onClick={runNow}>
                            <Play className="h-4 w-4" />
                            Exécuter maintenant
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
