import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface CrmSettingsProps {
    settings: { reactivation_months: number[]; lead_stage_id: string | null; retention_months: number };
    stages: { value: string; label: string }[];
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Réglages du CRM', href: '/admin/crm-settings' }];

/** « 6, 12 » → [6, 12]; anything not a number is sent as is so the server explains it. */
const parseMonths = (text: string): (number | string)[] =>
    text
        .split(/[,;\s]+/)
        .filter((v) => v !== '')
        .map((v) => (/^\d+$/.test(v) ? Number(v) : v));

export default function CrmSettings({ settings, stages, status }: CrmSettingsProps) {
    const { data, setData, transform, put, processing, errors } = useForm({
        reactivation: settings.reactivation_months.join(', '),
        lead_stage_id: settings.lead_stage_id ?? '',
        retention_months: String(settings.retention_months),
    });
    const monthErrors = Object.entries(errors)
        .filter(([key]) => key.startsWith('reactivation_months'))
        .map(([, message]) => message);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((d) => ({
            reactivation_months: parseMonths(d.reactivation),
            lead_stage_id: d.lead_stage_id,
            retention_months: d.retention_months === '' ? null : Number(d.retention_months),
        }));
        put(route('admin.crm-settings.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Réglages du CRM" />
            <div className="flex flex-col gap-6 p-4">
                <Heading title="Réglages du CRM" description="Rappels de réactivation et calcul des tableaux de bord." />
                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <form onSubmit={submit} className="flex max-w-xl flex-col gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="reactivation">Rappels de réactivation (mois après la fin d'un dossier)</Label>
                        <Input
                            id="reactivation"
                            value={data.reactivation}
                            onChange={(e) => setData('reactivation', e.target.value)}
                            placeholder="6, 12"
                        />
                        <p className="text-muted-foreground text-xs">
                            Une action « Appel » est créée pour le responsable de la fiche client. Laissez vide pour ne créer aucun rappel.
                        </p>
                        <InputError message={monthErrors[0]} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="lead_stage_id">Un client devient un lead à l'étape</Label>
                        <select
                            id="lead_stage_id"
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            value={data.lead_stage_id}
                            onChange={(e) => setData('lead_stage_id', e.target.value)}
                        >
                            {stages.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.lead_stage_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="retention_months">Fenêtre du taux de rétention (mois)</Label>
                        <Input
                            id="retention_months"
                            inputMode="numeric"
                            value={data.retention_months}
                            onChange={(e) => setData('retention_months', e.target.value.replace(/\D/g, ''))}
                        />
                        <InputError message={errors.retention_months} />
                    </div>

                    <Button type="submit" disabled={processing} className="self-start">
                        Enregistrer
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
