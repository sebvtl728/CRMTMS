import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { AUDIENCE_LABELS, KIND_LABELS, QUESTION_TYPES } from '@/pages/admin/questionnaires/labels';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

// A type (not an interface): useForm needs its index signature.
type Question = {
    id: string | null;
    type: string;
    label: string;
    required: boolean;
    options?: string[];
};

interface Template {
    id: string;
    name: string;
    kind: string;
    audience: string;
    active: boolean;
    questions: Question[];
}

const select = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function QuestionnaireForm({ template }: { template: Template | null }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Questionnaires', href: '/admin/questionnaires' },
        { title: template ? template.name : 'Nouveau modèle', href: '#' },
    ];
    const { data, setData, post, put, processing, errors } = useForm({
        name: template?.name ?? '',
        kind: template?.kind ?? 'hot',
        audience: template?.audience ?? 'trainee',
        active: template?.active ?? true,
        questions: template?.questions ?? ([{ id: null, type: 'rating', label: '', required: true }] as Question[]),
    });
    const err = errors as Record<string, string>;

    const setQuestion = (i: number, patch: Partial<Question>) =>
        setData(
            'questions',
            data.questions.map((q, j) => (j === i ? { ...q, ...patch } : q)),
        );
    const move = (i: number, by: number) => {
        const list = [...data.questions];
        const [q] = list.splice(i, 1);
        list.splice(i + by, 0, q);
        setData('questions', list);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (template) {
            put(route('admin.questionnaires.update', template.id));
        } else {
            post(route('admin.questionnaires.store'));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={template ? template.name : 'Nouveau modèle'} />
            <form onSubmit={submit} className="flex max-w-3xl flex-col gap-6 p-4">
                <Heading title={template ? 'Modifier le modèle' : 'Nouveau modèle de questionnaire'} />

                <div className="grid gap-2">
                    <Label htmlFor="name">Nom</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    <InputError message={errors.name} />
                </div>
                <div className="flex flex-wrap gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="kind">Type</Label>
                        <select id="kind" className={select} value={data.kind} onChange={(e) => setData('kind', e.target.value)}>
                            {Object.entries(KIND_LABELS).map(([v, l]) => (
                                <option key={v} value={v}>
                                    {l}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.kind} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="audience">Destinataires</Label>
                        <select id="audience" className={select} value={data.audience} onChange={(e) => setData('audience', e.target.value)}>
                            {Object.entries(AUDIENCE_LABELS).map(([v, l]) => (
                                <option key={v} value={v}>
                                    {l}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.audience} />
                    </div>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm">
                        <input type="checkbox" checked={data.active} onChange={(e) => setData('active', e.target.checked)} />
                        Actif
                    </label>
                </div>

                <fieldset className="grid gap-3">
                    <legend className="mb-1 text-sm font-medium">Questions</legend>
                    <InputError message={err.questions} />
                    {data.questions.map((q, i) => (
                        <div key={i} className="grid gap-2 rounded-xl border p-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-muted-foreground text-sm">{i + 1}.</span>
                                <select
                                    aria-label="Type de question"
                                    className={select}
                                    value={q.type}
                                    onChange={(e) =>
                                        setQuestion(i, {
                                            type: e.target.value,
                                            options: e.target.value === 'choice' ? (q.options ?? ['Oui', 'Non']) : undefined,
                                        })
                                    }
                                >
                                    {Object.entries(QUESTION_TYPES).map(([v, l]) => (
                                        <option key={v} value={v}>
                                            {l}
                                        </option>
                                    ))}
                                </select>
                                <label className="flex items-center gap-1 text-sm">
                                    <input type="checkbox" checked={q.required} onChange={(e) => setQuestion(i, { required: e.target.checked })} />
                                    Obligatoire
                                </label>
                                <span className="ml-auto flex gap-1">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        disabled={i === 0}
                                        onClick={() => move(i, -1)}
                                        aria-label="Monter"
                                    >
                                        ↑
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        disabled={i === data.questions.length - 1}
                                        onClick={() => move(i, 1)}
                                        aria-label="Descendre"
                                    >
                                        ↓
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            setData(
                                                'questions',
                                                data.questions.filter((_, j) => j !== i),
                                            )
                                        }
                                    >
                                        Retirer
                                    </Button>
                                </span>
                            </div>
                            <Input aria-label="Libellé de la question" value={q.label} onChange={(e) => setQuestion(i, { label: e.target.value })} />
                            <InputError message={err[`questions.${i}.label`] ?? err[`questions.${i}.type`]} />
                            {q.type === 'choice' && (
                                <div className="grid gap-1">
                                    <Label className="text-sm">Choix proposés (un par ligne)</Label>
                                    <textarea
                                        className="border-input min-h-20 rounded-md border p-2 text-sm"
                                        value={(q.options ?? []).join('\n')}
                                        onChange={(e) => setQuestion(i, { options: e.target.value.split('\n') })}
                                    />
                                    <InputError message={err[`questions.${i}.options`]} />
                                </div>
                            )}
                        </div>
                    ))}
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setData('questions', [...data.questions, { id: null, type: 'rating', label: '', required: true }])}
                        >
                            Ajouter une question
                        </Button>
                    </div>
                </fieldset>

                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        Enregistrer
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href={route('admin.questionnaires.index')}>Annuler</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
