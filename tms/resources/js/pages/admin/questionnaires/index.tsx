import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { AUDIENCE_LABELS, KIND_LABELS } from '@/pages/admin/questionnaires/labels';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface TemplateRow {
    id: string;
    name: string;
    kind: string;
    audience: string;
    active: boolean;
    is_default: boolean;
    questions_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Questionnaires', href: '/admin/questionnaires' }];

export default function QuestionnairesIndex({ templates, cold_months, status }: { templates: TemplateRow[]; cold_months: number; status?: string }) {
    const delay = useForm({ months: String(cold_months) });
    const submitDelay: FormEventHandler = (e) => {
        e.preventDefault();
        delay.put(route('admin.questionnaires.delay'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Questionnaires" />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Questionnaires de satisfaction"
                    description="Les modèles par défaut servent aux questionnaires à chaud (fin de session) et à froid (quelques mois après). Une session garde la copie des questions qu'elle a envoyées."
                />
                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <div>
                    <Button asChild>
                        <Link href={route('admin.questionnaires.create')}>Nouveau modèle</Link>
                    </Button>
                </div>

                <ul className="grid gap-3">
                    {templates.map((t) => (
                        <li key={t.id} className="flex flex-wrap items-center gap-2 rounded-xl border p-4">
                            <Link href={route('admin.questionnaires.edit', t.id)} className="font-medium underline">
                                {t.name}
                            </Link>
                            <Badge variant="outline">{KIND_LABELS[t.kind]}</Badge>
                            <Badge variant="outline">{AUDIENCE_LABELS[t.audience]}</Badge>
                            {t.is_default && <Badge>Par défaut</Badge>}
                            {!t.active && <Badge variant="secondary">Inactif</Badge>}
                            <span className="text-muted-foreground text-sm">
                                {t.questions_count} question{t.questions_count > 1 ? 's' : ''}
                            </span>
                            <span className="ml-auto flex gap-2">
                                {!t.is_default && t.active && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => router.post(route('admin.questionnaires.default', t.id), {}, { preserveScroll: true })}
                                    >
                                        Utiliser par défaut
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        if (confirm(`Supprimer le modèle « ${t.name} » ? Les questionnaires déjà envoyés sont conservés.`)) {
                                            router.delete(route('admin.questionnaires.destroy', t.id), { preserveScroll: true });
                                        }
                                    }}
                                >
                                    Supprimer
                                </Button>
                            </span>
                        </li>
                    ))}
                    {templates.length === 0 && <li className="text-muted-foreground text-sm">Aucun modèle.</li>}
                </ul>

                <Heading
                    title="Questionnaire à froid"
                    description="Envoyé automatiquement aux stagiaires quelques mois après la fin de leur session, avec le modèle à froid par défaut."
                />
                <form onSubmit={submitDelay} className="flex max-w-xl flex-col gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="cold_months">Délai après la fin de la session (mois, 0 = jamais)</Label>
                        <Input
                            id="cold_months"
                            type="number"
                            min={0}
                            max={24}
                            step={1}
                            className="w-40"
                            value={delay.data.months}
                            onChange={(e) => delay.setData('months', e.target.value)}
                        />
                        <InputError message={delay.errors.months} />
                    </div>
                    <div>
                        <Button type="submit" disabled={delay.processing}>
                            Enregistrer le délai
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
