import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { type Choice, newId, type Question, QUESTION_TYPES, type QuestionType, type Section, type TestContent } from '@/types/online-tests';
import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface EditProps {
    test: {
        id: string;
        title: string;
        time_limit_minutes: number | null;
        das_ids: string[];
        training_ids: string[];
        content: TestContent;
        published_version: number | null;
        published_at: string | null;
    };
    options: { das: Option[]; trainings: Option[] };
    status?: string;
}

const textarea = 'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden';

function move<T>(list: T[], index: number, delta: number): T[] {
    const target = index + delta;
    if (target < 0 || target >= list.length) return list;
    const copy = [...list];
    [copy[index], copy[target]] = [copy[target], copy[index]];
    return copy;
}

function blankQuestion(type: QuestionType = 'short_text'): Question {
    return { id: newId(), type, label: '', points: 0, required: true, choices: [], answer: null };
}

/** One question: type, wording, supporting text, choices and right answer, points. */
function QuestionEditor({
    question,
    errorPrefix,
    errors,
    onChange,
    onRemove,
    onMove,
}: {
    question: Question;
    errorPrefix: string;
    errors: Record<string, string>;
    onChange: (q: Question) => void;
    onRemove: () => void;
    onMove: (delta: number) => void;
}) {
    const set = (patch: Partial<Question>) => onChange({ ...question, ...patch });
    const choices = question.choices ?? [];
    const isChoice = question.type === 'single_choice' || question.type === 'multiple_choice';
    const answerList = Array.isArray(question.answer) ? question.answer : [];

    return (
        <div className="grid gap-3 rounded-lg border p-3">
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1">
                    <Label className="text-xs">Type</Label>
                    <select
                        className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        value={question.type}
                        onChange={(e) => set({ type: e.target.value as QuestionType, answer: null })}
                    >
                        {QUESTION_TYPES.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="grid w-24 gap-1">
                    <Label className="text-xs">Points</Label>
                    <Input type="number" min={0} max={100} value={question.points} onChange={(e) => set({ points: Number(e.target.value) || 0 })} />
                </div>
                <Label className="flex items-center gap-2 pb-2 text-sm font-normal">
                    <input type="checkbox" checked={question.required} onChange={(e) => set({ required: e.target.checked })} />
                    Réponse obligatoire
                </Label>
                <div className="ml-auto flex gap-1">
                    <Button type="button" size="icon" variant="ghost" aria-label="Monter la question" onClick={() => onMove(-1)}>
                        <ArrowUp className="h-4 w-4" />
                    </Button>
                    <Button type="button" size="icon" variant="ghost" aria-label="Descendre la question" onClick={() => onMove(1)}>
                        <ArrowDown className="h-4 w-4" />
                    </Button>
                    <Button type="button" size="icon" variant="ghost" aria-label="Supprimer la question" onClick={onRemove}>
                        <Trash2 className="h-4 w-4" />
                    </Button>
                </div>
            </div>
            <div className="grid gap-1">
                <Label className="text-xs">Question *</Label>
                <Input value={question.label} onChange={(e) => set({ label: e.target.value })} maxLength={2000} />
                <InputError message={errors[`${errorPrefix}.label`] ?? errors[`${errorPrefix}.type`] ?? errors[`${errorPrefix}.points`]} />
            </div>
            <div className="grid gap-1">
                <Label className="text-xs">Texte d’appui (texte à corriger, article…)</Label>
                <textarea className={textarea} rows={3} value={question.support_text ?? ''} onChange={(e) => set({ support_text: e.target.value })} />
            </div>

            {isChoice && (
                <div className="grid gap-2">
                    <Label className="text-xs">Choix (cochez la ou les bonnes réponses ; aucune = question de profil)</Label>
                    {choices.map((c: Choice, i) => (
                        <div key={c.id} className="flex items-center gap-2">
                            <input
                                type={question.type === 'single_choice' ? 'radio' : 'checkbox'}
                                name={`answer-${question.id}`}
                                aria-label={`Bonne réponse : ${c.label || `choix ${i + 1}`}`}
                                checked={question.type === 'single_choice' ? question.answer === c.id : answerList.includes(c.id)}
                                onChange={(e) =>
                                    question.type === 'single_choice'
                                        ? set({ answer: c.id })
                                        : set({ answer: e.target.checked ? [...answerList, c.id] : answerList.filter((a) => a !== c.id) })
                                }
                            />
                            <Input
                                value={c.label}
                                aria-label={`Choix ${i + 1}`}
                                onChange={(e) => set({ choices: choices.map((x) => (x.id === c.id ? { ...x, label: e.target.value } : x)) })}
                            />
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                aria-label="Retirer le choix"
                                onClick={() =>
                                    set({
                                        choices: choices.filter((x) => x.id !== c.id),
                                        answer: question.type === 'single_choice' && question.answer === c.id ? null : question.answer,
                                    })
                                }
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        </div>
                    ))}
                    <div>
                        <Button type="button" size="sm" variant="outline" onClick={() => set({ choices: [...choices, { id: newId(), label: '' }] })}>
                            <Plus className="h-4 w-4" />
                            Ajouter un choix
                        </Button>
                    </div>
                    <InputError message={errors[`${errorPrefix}.choices`] ?? errors[`${errorPrefix}.answer`]} />
                </div>
            )}

            {question.type === 'true_false' && (
                <div className="flex gap-4 text-sm">
                    <span className="text-muted-foreground text-xs">Bonne réponse :</span>
                    {[
                        { v: true, l: 'Vrai' },
                        { v: false, l: 'Faux' },
                        { v: null, l: 'Aucune (profil)' },
                    ].map((o) => (
                        <Label key={String(o.v)} className="flex items-center gap-1 font-normal">
                            <input
                                type="radio"
                                name={`tf-${question.id}`}
                                checked={question.answer === o.v || (o.v === null && typeof question.answer !== 'boolean')}
                                onChange={() => set({ answer: o.v })}
                            />
                            {o.l}
                        </Label>
                    ))}
                </div>
            )}

            {question.type === 'number' && (
                <div className="flex flex-wrap gap-3">
                    <div className="grid gap-1">
                        <Label className="text-xs">Bonne réponse (laisser vide : correction à la main)</Label>
                        <Input
                            value={typeof question.answer === 'string' ? question.answer : ''}
                            onChange={(e) => set({ answer: e.target.value })}
                            placeholder="38,50"
                        />
                    </div>
                    <div className="grid w-32 gap-1">
                        <Label className="text-xs">Tolérance</Label>
                        <Input value={question.tolerance ?? '0.01'} onChange={(e) => set({ tolerance: e.target.value })} />
                    </div>
                    <InputError message={errors[`${errorPrefix}.answer`]} />
                </div>
            )}
        </div>
    );
}

export default function OnlineTestEdit({ test, options, status }: EditProps) {
    const [title, setTitle] = useState(test.title);
    const [limit, setLimit] = useState(test.time_limit_minutes === null ? '' : String(test.time_limit_minutes));
    const [das, setDas] = useState<string[]>(test.das_ids);
    const [trainings, setTrainings] = useState<string[]>(test.training_ids);
    const [sections, setSections] = useState<Section[]>(test.content.sections ?? []);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<string | undefined>(status);
    const [dirty, setDirty] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Tests en ligne', href: '/admissions/online-tests' },
        { title: test.title, href: '#' },
    ];

    const change = (next: Section[]) => {
        setSections(next);
        setDirty(true);
    };
    const updateSection = (i: number, patch: Partial<Section>) => change(sections.map((s, j) => (j === i ? { ...s, ...patch } : s)));
    const total = sections.reduce((sum, s) => sum + s.questions.reduce((t, q) => t + (Number(q.points) || 0), 0), 0);

    const save = async (): Promise<boolean> => {
        setSaving(true);
        setErrors({});
        const token = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.split('=')[1] ?? '',
        );
        const response = await fetch(`/admissions/online-tests/${test.id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
            body: JSON.stringify({
                title,
                time_limit_minutes: limit === '' ? null : Number(limit),
                das_ids: das,
                training_ids: trainings,
                content: { sections },
            }),
        });
        setSaving(false);
        const json = await response.json().catch(() => ({}));
        if (!response.ok) {
            const errs: Record<string, string> = {};
            Object.entries((json.errors ?? {}) as Record<string, string[]>).forEach(([k, v]) => (errs[k.replace(/^content\./, '')] = v[0]));
            setErrors(errs);
            setMessage(json.message && !json.errors ? json.message : 'Le brouillon contient des erreurs : corrigez les champs signalés.');
            return false;
        }
        setDirty(false);
        setMessage(json.status);
        return true;
    };

    const publish = async () => {
        if (await save()) router.post(`/admissions/online-tests/${test.id}/publish`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={test.title} />
            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <Heading
                    title={test.title}
                    description={
                        test.published_version === null
                            ? 'Brouillon : publiez-le pour pouvoir l’envoyer aux candidats.'
                            : `Version ${test.published_version} publiée. Vos modifications restent en brouillon jusqu’à la prochaine publication ; un candidat garde toujours la version qu’il a ouverte.`
                    }
                />
                {message && <div className="text-sm font-medium text-green-700">{message}</div>}
                <InputError message={errors.content ?? errors.sections} />

                <section className="grid gap-4 rounded-xl border p-4 sm:grid-cols-2">
                    <div className="grid gap-1">
                        <Label htmlFor="title">Titre *</Label>
                        <Input id="title" value={title} maxLength={200} onChange={(e) => (setTitle(e.target.value), setDirty(true))} />
                        <InputError message={errors.title} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="limit">Durée limite (minutes, vide = libre)</Label>
                        <Input
                            id="limit"
                            type="number"
                            min={5}
                            max={240}
                            value={limit}
                            onChange={(e) => (setLimit(e.target.value), setDirty(true))}
                        />
                        <InputError message={errors.time_limit_minutes} />
                    </div>
                    <fieldset className="grid gap-1">
                        <legend className="mb-1 text-sm font-medium">DAS concernés</legend>
                        {options.das.map((d) => (
                            <Label key={d.value} className="flex items-center gap-2 font-normal">
                                <input
                                    type="checkbox"
                                    checked={das.includes(d.value)}
                                    onChange={(e) => (
                                        setDas(e.target.checked ? [...das, d.value] : das.filter((x) => x !== d.value)), setDirty(true)
                                    )}
                                />
                                {d.label}
                            </Label>
                        ))}
                    </fieldset>
                    <fieldset className="grid max-h-48 gap-1 overflow-y-auto">
                        <legend className="mb-1 text-sm font-medium">Formations concernées</legend>
                        {options.trainings.map((t) => (
                            <Label key={t.value} className="flex items-center gap-2 font-normal">
                                <input
                                    type="checkbox"
                                    checked={trainings.includes(t.value)}
                                    onChange={(e) => (
                                        setTrainings(e.target.checked ? [...trainings, t.value] : trainings.filter((x) => x !== t.value)),
                                        setDirty(true)
                                    )}
                                />
                                {t.label}
                            </Label>
                        ))}
                    </fieldset>
                </section>

                {sections.map((section, si) => (
                    <section key={si} aria-label={`Section ${si + 1}`} className="grid gap-3 rounded-xl border p-4">
                        <div className="flex items-end gap-2">
                            <div className="grid flex-1 gap-1">
                                <Label className="text-xs">Section {si + 1} *</Label>
                                <Input
                                    value={section.title}
                                    onChange={(e) => updateSection(si, { title: e.target.value })}
                                    placeholder="Ex. Mathématiques commerciales"
                                />
                                <InputError message={errors[`sections.${si}.title`]} />
                            </div>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                aria-label="Monter la section"
                                onClick={() => change(move(sections, si, -1))}
                            >
                                <ArrowUp className="h-4 w-4" />
                            </Button>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                aria-label="Descendre la section"
                                onClick={() => change(move(sections, si, 1))}
                            >
                                <ArrowDown className="h-4 w-4" />
                            </Button>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                aria-label="Supprimer la section"
                                onClick={() => change(sections.filter((_, j) => j !== si))}
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        </div>
                        <textarea
                            className={textarea}
                            rows={2}
                            placeholder="Consigne de la section (facultatif)"
                            value={section.intro ?? ''}
                            onChange={(e) => updateSection(si, { intro: e.target.value })}
                        />
                        {section.questions.map((q, qi) => (
                            <QuestionEditor
                                key={q.id}
                                question={q}
                                errorPrefix={`sections.${si}.questions.${qi}`}
                                errors={errors}
                                onChange={(nq) => updateSection(si, { questions: section.questions.map((x, j) => (j === qi ? nq : x)) })}
                                onRemove={() => updateSection(si, { questions: section.questions.filter((_, j) => j !== qi) })}
                                onMove={(delta) => updateSection(si, { questions: move(section.questions, qi, delta) })}
                            />
                        ))}
                        <div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => updateSection(si, { questions: [...section.questions, blankQuestion()] })}
                            >
                                <Plus className="h-4 w-4" />
                                Ajouter une question
                            </Button>
                        </div>
                    </section>
                ))}

                <div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => change([...sections, { title: '', intro: null, questions: [blankQuestion()] }])}
                    >
                        <Plus className="h-4 w-4" />
                        Ajouter une section
                    </Button>
                </div>

                <div className="bg-background sticky bottom-0 flex flex-wrap items-center gap-3 border-t py-3">
                    <span className="text-sm">
                        Total : <strong>{total}</strong> points{dirty && ' · modifications non enregistrées'}
                    </span>
                    <Button type="button" variant="outline" onClick={() => void save()} disabled={saving}>
                        {saving && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Enregistrer le brouillon
                    </Button>
                    <Button type="button" onClick={() => void publish()} disabled={saving}>
                        Publier
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
