import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Filters {
    from: string;
    to: string;
    kind: string | null;
    audience: string | null;
    training_id: string | null;
}

interface Row {
    campaign_id: string;
    session_id: string;
    session_label: string;
    ends_on: string;
    label: string;
    invited: number;
    answered: number;
    response_rate: number | null;
    average_label: string;
    satisfaction_rate: number | null;
}

interface Totals {
    campaigns: number;
    invited: number;
    answered: number;
    response_rate: number | null;
    average_label: string;
    satisfaction_rate: number | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Résultats des questionnaires', href: '/qualite/questionnaires' }];
const select = 'border-input bg-background h-9 rounded-md border px-3 text-sm';
const percent = (v: number | null) => (v === null ? '-' : `${v} %`);

/** Qualité › Questionnaires: the satisfaction results of every session over a period, with the export. */
export default function QuestionnairesResults({
    filters,
    rows,
    totals,
    training_options: trainings,
}: {
    filters: Filters;
    rows: Row[];
    totals: Totals;
    training_options: { value: string; label: string }[];
}) {
    const [form, setForm] = useState({
        from: filters.from,
        to: filters.to,
        kind: filters.kind ?? '',
        audience: filters.audience ?? '',
        training_id: filters.training_id ?? '',
    });
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('qualite.questionnaires.index'), query, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Résultats des questionnaires" />
            <div className="flex flex-col gap-4 p-4">
                <Heading
                    title="Résultats des questionnaires"
                    description="Satisfaction des stagiaires et des entreprises, par session terminée sur la période (indicateurs Qualiopi 30 et 2). Satisfait = moyenne des notes d'au moins 4/5."
                />
                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="from">Fin de session du</Label>
                        <Input id="from" type="date" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="to">au</Label>
                        <Input id="to" type="date" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="kind">Type</Label>
                        <select id="kind" className={select} value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })}>
                            <option value="">Tous</option>
                            <option value="hot">À chaud</option>
                            <option value="cold">À froid</option>
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="audience">Destinataires</Label>
                        <select
                            id="audience"
                            className={select}
                            value={form.audience}
                            onChange={(e) => setForm({ ...form, audience: e.target.value })}
                        >
                            <option value="">Tous</option>
                            <option value="trainee">Stagiaires</option>
                            <option value="client">Entreprises</option>
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="training_id">Formation</Label>
                        <select
                            id="training_id"
                            className={select}
                            value={form.training_id}
                            onChange={(e) => setForm({ ...form, training_id: e.target.value })}
                        >
                            <option value="">Toutes</option>
                            {trainings.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button type="submit">Filtrer</Button>
                    <Button variant="outline" asChild>
                        <a href={route('qualite.questionnaires.export', query)}>
                            <Download className="h-4 w-4" />
                            Exporter (CSV)
                        </a>
                    </Button>
                </form>

                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <Stat label="Réponses" value={`${totals.answered} / ${totals.invited}`} />
                    <Stat label="Taux de réponse" value={percent(totals.response_rate)} />
                    <Stat label="Satisfaction moyenne" value={totals.average_label} />
                    <Stat label="Satisfaits (≥ 4/5)" value={percent(totals.satisfaction_rate)} />
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-left">
                            <tr>
                                <th className="p-2">Session</th>
                                <th className="p-2">Fin</th>
                                <th className="p-2">Questionnaire</th>
                                <th className="p-2 text-right">Réponses</th>
                                <th className="p-2 text-right">Taux de réponse</th>
                                <th className="p-2 text-right">Moyenne</th>
                                <th className="p-2 text-right">Satisfaits</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.campaign_id} className="border-t">
                                    <td className="p-2">
                                        <Link href={`/sessions/${r.session_id}?etape=stagiaires#questionnaires`} className="underline">
                                            {r.session_label}
                                        </Link>
                                    </td>
                                    <td className="p-2">{formatDate(r.ends_on)}</td>
                                    <td className="p-2">{r.label}</td>
                                    <td className="p-2 text-right">
                                        {r.answered} / {r.invited}
                                    </td>
                                    <td className="p-2 text-right">{percent(r.response_rate)}</td>
                                    <td className="p-2 text-right">{r.average_label}</td>
                                    <td className="p-2 text-right">{percent(r.satisfaction_rate)}</td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground p-4 text-center">
                                        Aucun questionnaire sur la période.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-md border p-2">
            <div className="text-muted-foreground text-xs">{label}</div>
            <div className="text-lg font-semibold">{value}</div>
        </div>
    );
}
