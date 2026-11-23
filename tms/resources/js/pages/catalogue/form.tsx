import { RncpPanel } from '@/components/catalogue/rncp-panel';
import TrainingAssistant, { type AssistantPatch } from '@/components/catalogue/training-assistant';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { amountInput, formatSize, itemError, type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface FormProps {
    training: {
        id: string;
        code: string | null;
        rncp_code: string | null;
        bpf_objective: string | null;
        nsf_code: string | null;
        competences_text: string;
        title: string;
        das_id: string;
        duration_hours: string | null;
        duration_days: string | null;
        centre_hours: string | null;
        company_hours: string | null;
        audience: string | null;
        prerequisites: string | null;
        price_company: string | null;
        price_individual: string | null;
        is_active: boolean;
        description: string | null;
        objectives: string[];
        methods: string | null;
        evaluation: string | null;
        sanction: string | null;
        accessibility: string | null;
        access_terms: string | null;
        results: string | null;
        modules: { id: string; title: string; duration_hours: string | null; modality_id: string | null; content: string | null }[];
        modules_hours: string;
        modules_gap: boolean;
        funding_mode_ids: string[];
    } | null;
    options: { das: Option[]; funding_mode: Option[]; modality: Option[]; bpf_objective: Option[]; nsf: Option[] };
    assistant?: { configured: boolean };
    brochures?: { id: string; title: string; original_name: string; size: number; source: 'training' | 'das' }[];
    can?: { brochures: boolean; manage?: boolean };
    status?: string | null;
}

type ModuleForm = { uid: string; id?: string; title: string; duration_hours: string; modality_id: string; content: string };

type SheetText = 'description' | 'methods' | 'evaluation' | 'sanction' | 'accessibility' | 'access_terms' | 'results';

type TrainingForm = {
    description: string;
    objectives: string[];
    methods: string;
    evaluation: string;
    sanction: string;
    accessibility: string;
    access_terms: string;
    results: string;
    modules: ModuleForm[];
    code: string;
    rncp_code: string;
    bpf_objective: string;
    nsf_code: string;
    competences_text: string;
    title: string;
    das_id: string;
    duration_hours: string;
    duration_days: string;
    centre_hours: string;
    company_hours: string;
    audience: string;
    prerequisites: string;
    price_company: string;
    price_individual: string;
    funding_mode_ids: string[];
};

/** Stored decimal (« 14.50 ») as typed in a form (« 14,5 »). */
function decimalInput(value: string | null | undefined): string {
    if (!value) return '';
    if (!value.includes('.')) return value;

    return value.replace(/\.?0+$/, '').replace('.', ',');
}

// Local keys of the modules added in the form (crypto.randomUUID is unavailable outside secure contexts).
let newModuleCount = 0;

const textareaClass = 'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden';

export default function TrainingFormPage({ training, options, assistant, brochures = [], can }: FormProps) {
    const editing = training !== null;
    // One copy per click: « Dupliquer » stays disabled until the request ends.
    const [duplicating, setDuplicating] = useState(false);
    const [reviewing, setReviewing] = useState(false);

    const { data, setData, post, put, processing, errors, isDirty } = useForm<TrainingForm>({
        code: training?.code ?? '',
        rncp_code: training?.rncp_code ?? '',
        bpf_objective: training?.bpf_objective ?? '',
        nsf_code: training?.nsf_code ?? '',
        competences_text: training?.competences_text ?? '',
        title: training?.title ?? '',
        das_id: training?.das_id ?? '',
        duration_hours: decimalInput(training?.duration_hours),
        duration_days: decimalInput(training?.duration_days),
        centre_hours: decimalInput(training?.centre_hours),
        company_hours: decimalInput(training?.company_hours),
        audience: training?.audience ?? '',
        prerequisites: training?.prerequisites ?? '',
        price_company: amountInput(training?.price_company),
        price_individual: amountInput(training?.price_individual),
        funding_mode_ids: training?.funding_mode_ids ?? [],
        description: training?.description ?? '',
        objectives: training?.objectives ?? [],
        methods: training?.methods ?? '',
        evaluation: training?.evaluation ?? '',
        sanction: training?.sanction ?? '',
        accessibility: training?.accessibility ?? '',
        access_terms: training?.access_terms ?? '',
        results: training?.results ?? '',
        modules: (training?.modules ?? []).map((m) => ({
            uid: m.id,
            id: m.id,
            title: m.title,
            duration_hours: decimalInput(m.duration_hours),
            modality_id: m.modality_id ?? '',
            content: m.content ?? '',
        })),
    });

    const title = editing ? 'Modifier la formation' : 'Nouvelle formation';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Catalogue', href: '/catalogue' },
        { title: editing ? 'Modifier' : 'Nouvelle formation', href: '#' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (editing) {
            put(route('catalogue.update', training.id), { preserveScroll: true });
        } else {
            post(route('catalogue.store'), { preserveScroll: true });
        }
    };

    const text = (
        key: 'code' | 'rncp_code' | 'duration_hours' | 'duration_days' | 'centre_hours' | 'company_hours' | 'price_company' | 'price_individual',
        label: string,
        props: Record<string, unknown> = {},
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={key}>{label}</Label>
            <Input id={key} value={data[key]} onChange={(e) => setData(key, e.target.value)} {...props} />
            <InputError message={errors[key]} />
        </div>
    );

    // Native select: the NSF list has 93 entries, typing a code jumps to it.
    const bpfSelect = (key: 'bpf_objective' | 'nsf_code', label: string, list: Option[], help: string) => (
        <div className="grid gap-2">
            <Label htmlFor={key}>{label}</Label>
            <select id={key} value={data[key]} onChange={(e) => setData(key, e.target.value)} className={textareaClass}>
                <option value="">Non renseigné</option>
                {list.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
            <p className="text-muted-foreground text-xs">{help}</p>
            <InputError message={errors[key]} />
        </div>
    );

    const toggleFunding = (id: string, checked: boolean) =>
        setData('funding_mode_ids', checked ? [...data.funding_mode_ids, id] : data.funding_mode_ids.filter((f) => f !== id));

    const area = (key: SheetText, label: string, rows = 3) => (
        <div className="grid gap-2 md:col-span-2">
            <Label htmlFor={key}>{label}</Label>
            <textarea id={key} rows={rows} value={data[key]} onChange={(e) => setData(key, e.target.value)} className={textareaClass} />
            <InputError message={errors[key]} />
        </div>
    );

    const setObjective = (i: number, value: string) =>
        setData(
            'objectives',
            data.objectives.map((o, j) => (j === i ? value : o)),
        );
    const moveItem = <T,>(list: T[], i: number, by: number): T[] => {
        const j = i + by;
        if (j < 0 || j >= list.length) return list;
        const next = [...list];
        [next[i], next[j]] = [next[j], next[i]];

        return next;
    };
    const setModule = (i: number, patch: Partial<ModuleForm>) =>
        setData(
            'modules',
            data.modules.map((m, j) => (j === i ? { ...m, ...patch } : m)),
        );

    // Sum of the modules (hours typed the French way) against the duration of the training: a warning only.
    const toHours = (v: string) => Number.parseFloat(v.replace(/\s/g, '').replace(',', '.'));
    const modulesSum = data.modules.reduce((sum, m) => sum + (Number.isFinite(toHours(m.duration_hours)) ? toHours(m.duration_hours) : 0), 0);
    // The modules are taught in the centre: compared with the centre hours when given.
    const duration = toHours(data.centre_hours) || toHours(data.duration_hours);
    const durationLabel = toHours(data.centre_hours) ? 'aux heures en centre' : 'à la durée de la formation';
    const frenchHours = (n: number) => String(Math.round(n * 100) / 100).replace('.', ',');
    // 7 h a day (Training::HOURS_PER_DAY): an empty duration is filled from the other, never overwritten.
    const fillOtherDuration = (from: 'duration_hours' | 'duration_days') => {
        const value = toHours(data[from]);
        if (!Number.isFinite(value) || value <= 0) return;
        if (from === 'duration_hours' && data.duration_days.trim() === '') setData('duration_days', frenchHours(Math.round((value / 7) * 2) / 2));
        if (from === 'duration_days' && data.duration_hours.trim() === '') setData('duration_hours', frenchHours(value * 7));
    };
    // « Répartir les heures »: the centre hours (else the duration) over the modules, in half hours, the last one taking the rest.
    const spreadHours = () => {
        const total = Math.round(duration * 100);
        if (!Number.isFinite(duration) || total <= 0 || data.modules.length === 0) return;
        if (data.modules.some((m) => m.duration_hours.trim() !== '') && !confirm('Remplacer les durées des modules par une répartition égale ?'))
            return;
        const each = Math.floor(total / data.modules.length / 50) * 50;
        setData(
            'modules',
            data.modules.map((m, i) => ({
                ...m,
                duration_hours: frenchHours((i === data.modules.length - 1 ? total - each * (data.modules.length - 1) : each) / 100),
            })),
        );
    };
    const partsGap =
        data.centre_hours.trim() !== '' &&
        data.company_hours.trim() !== '' &&
        Number.isFinite(toHours(data.duration_hours)) &&
        Math.abs(toHours(data.centre_hours) + toHours(data.company_hours) - toHours(data.duration_hours)) > 0.004;
    // As loaded: the server's verdict; once the form is edited: the live sum.
    const gap = isDirty
        ? data.modules.length > 0 && Number.isFinite(duration) && Math.abs(modulesSum - duration) > 0.004
        : (training?.modules_gap ?? false);
    const hoursText = (n: number) => String(Math.round(n * 100) / 100).replace('.', ',');
    const nested = (key: string): string | undefined => (errors as Record<string, string | undefined>)[key];

    // The AI proposal kept by the user fills the form; nothing is saved until « Enregistrer ».
    const applyProposal = (patch: AssistantPatch) => {
        const modules = [...data.modules];
        for (const { at, module } of patch.modules) {
            const values = {
                title: module.title,
                duration_hours: module.duration_hours ?? '',
                modality_id: module.modality_id ?? '',
                content: module.content ?? '',
            };
            if (at !== null && modules[at]) {
                modules[at] = { ...modules[at], ...values };
            } else if (modules.length < 30) {
                modules.push({ uid: `new-${++newModuleCount}`, ...values });
            }
        }
        setData((previous) => ({
            ...previous,
            ...patch.values,
            ...(patch.objectives ? { objectives: patch.objectives } : {}),
            modules,
        }));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title={title}
                    description={editing && !training.is_active ? 'Formation désactivée : elle n’est plus proposée.' : undefined}
                />

                <TrainingAssistant
                    configured={assistant?.configured ?? false}
                    current={data}
                    options={{ das: options.das, modality: options.modality }}
                    onApply={applyProposal}
                    onReviewChange={setReviewing}
                />

                <form onSubmit={submit} noValidate>
                    {/* Read-only while an AI proposal is reviewed: what it shows as current stays current. */}
                    <fieldset disabled={reviewing} className="flex min-w-0 flex-col gap-6">
                        <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2">
                            <legend className="px-2 text-sm font-semibold">Présentation</legend>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="title">Intitulé *</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    maxLength={200}
                                    required
                                    autoFocus
                                    placeholder="Ex. HACCP 3 jours"
                                />
                                <InputError message={errors.title} />
                            </div>
                            {text('code', 'Code', { maxLength: 20, placeholder: 'Ex. HACCP-3J' })}
                            {text('rncp_code', 'Code RNCP', { maxLength: 20, placeholder: 'Ex. RNCP36004' })}
                            <RncpPanel
                                code={data.rncp_code}
                                onApply={(s) =>
                                    setData((d) => ({
                                        ...d,
                                        bpf_objective: d.bpf_objective || (s.bpf_objective ?? ''),
                                        nsf_code: d.nsf_code || (s.nsf_code ?? ''),
                                        competences_text: d.competences_text.trim() !== '' ? d.competences_text : s.competences_text,
                                    }))
                                }
                            />
                            <div className="grid gap-2">
                                <Label htmlFor="das_id">DAS *</Label>
                                <Select value={data.das_id || undefined} onValueChange={(v) => setData('das_id', v)}>
                                    <SelectTrigger id="das_id">
                                        <SelectValue placeholder="Choisissez" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.das.map((o) => (
                                            <SelectItem key={o.value} value={o.value}>
                                                {o.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.das_id} />
                            </div>
                            {bpfSelect(
                                'bpf_objective',
                                'Objectif (BPF)',
                                options.bpf_objective,
                                'Sert au cadre F-3 du bilan pédagogique et financier.',
                            )}
                            {bpfSelect(
                                'nsf_code',
                                'Spécialité NSF (BPF)',
                                options.nsf,
                                "Nomenclature des spécialités de formation, cadre F-4 du BPF. Ex. 334 pour l'hôtellerie.",
                            )}
                            {text('duration_hours', 'Durée totale (heures)', {
                                inputMode: 'decimal',
                                placeholder: '21',
                                onBlur: () => fillOtherDuration('duration_hours'),
                            })}
                            {text('duration_days', 'Durée (jours, 7 h par jour)', {
                                inputMode: 'decimal',
                                placeholder: '3',
                                onBlur: () => fillOtherDuration('duration_days'),
                            })}
                            {text('centre_hours', 'Heures en centre (période de formation)', { inputMode: 'decimal', placeholder: '994' })}
                            {text('company_hours', 'Heures en entreprise', { inputMode: 'decimal', placeholder: '322' })}
                            {partsGap && (
                                <p className="text-xs text-amber-700 md:col-span-2 dark:text-amber-400">
                                    Heures en centre + heures en entreprise = {frenchHours(toHours(data.centre_hours) + toHours(data.company_hours))}{' '}
                                    h, différent de la durée totale ({frenchHours(toHours(data.duration_hours))} h).
                                </p>
                            )}
                            {text('price_company', 'Tarif entreprise HT par stagiaire (€)', { inputMode: 'decimal', placeholder: '1 250,00' })}
                            {text('price_individual', 'Tarif particulier HT par stagiaire (€)', { inputMode: 'decimal', placeholder: '990,00' })}
                            {area('description', 'Description courte')}
                        </fieldset>

                        <fieldset className="grid gap-4 rounded-xl border p-4" aria-label="Objectifs">
                            <legend className="px-2 text-sm font-semibold">Objectifs pédagogiques</legend>
                            {data.objectives.map((o, i) => (
                                <div key={i} className="grid gap-1">
                                    <div className="flex items-center gap-2">
                                        <Input
                                            aria-label={`Objectif ${i + 1}`}
                                            value={o}
                                            maxLength={500}
                                            onChange={(e) => setObjective(i, e.target.value)}
                                            placeholder="À l’issue de la formation, le stagiaire sait…"
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            aria-label={`Monter l’objectif ${i + 1}`}
                                            onClick={() => setData('objectives', moveItem(data.objectives, i, -1))}
                                        >
                                            <ArrowUp className="h-4 w-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            aria-label={`Descendre l’objectif ${i + 1}`}
                                            onClick={() => setData('objectives', moveItem(data.objectives, i, 1))}
                                        >
                                            <ArrowDown className="h-4 w-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            aria-label={`Supprimer l’objectif ${i + 1}`}
                                            onClick={() =>
                                                setData(
                                                    'objectives',
                                                    data.objectives.filter((_, j) => j !== i),
                                                )
                                            }
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                    <InputError message={nested(`objectives.${i}`)} />
                                </div>
                            ))}
                            <InputError message={errors.objectives} />
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={data.objectives.length >= 20}
                                    onClick={() => setData('objectives', [...data.objectives, ''])}
                                >
                                    <Plus className="h-4 w-4" /> Ajouter un objectif
                                </Button>
                            </div>
                        </fieldset>

                        <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2" aria-label="Public et prérequis">
                            <legend className="px-2 text-sm font-semibold">Public et prérequis</legend>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="audience">Public visé</Label>
                                <textarea
                                    id="audience"
                                    rows={3}
                                    value={data.audience}
                                    onChange={(e) => setData('audience', e.target.value)}
                                    className={textareaClass}
                                />
                                <InputError message={errors.audience} />
                            </div>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="prerequisites">Prérequis</Label>
                                <textarea
                                    id="prerequisites"
                                    rows={3}
                                    value={data.prerequisites}
                                    onChange={(e) => setData('prerequisites', e.target.value)}
                                    className={textareaClass}
                                />
                                <InputError message={errors.prerequisites} />
                            </div>
                        </fieldset>

                        <fieldset className="grid gap-4 rounded-xl border p-4" aria-label="Modules">
                            <legend className="px-2 text-sm font-semibold">Modules</legend>
                            {data.modules.map((m, i) => (
                                <div key={m.uid} className="grid gap-2 rounded-lg border p-3 md:grid-cols-[1fr_8rem_12rem]">
                                    <div className="grid gap-1">
                                        <Label htmlFor={`module-title-${i}`}>Module {i + 1} : intitulé *</Label>
                                        <Input
                                            id={`module-title-${i}`}
                                            value={m.title}
                                            maxLength={200}
                                            onChange={(e) => setModule(i, { title: e.target.value })}
                                        />
                                        <InputError message={nested(`modules.${i}.title`)} />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor={`module-hours-${i}`}>Durée (h)</Label>
                                        <Input
                                            id={`module-hours-${i}`}
                                            inputMode="decimal"
                                            value={m.duration_hours}
                                            onChange={(e) => setModule(i, { duration_hours: e.target.value })}
                                        />
                                        <InputError message={nested(`modules.${i}.duration_hours`)} />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor={`module-modality-${i}`}>Modalité</Label>
                                        <Select
                                            value={m.modality_id || '__none'}
                                            onValueChange={(v) => setModule(i, { modality_id: v === '__none' ? '' : v })}
                                        >
                                            <SelectTrigger id={`module-modality-${i}`}>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none">Non précisée</SelectItem>
                                                {options.modality.map((o) => (
                                                    <SelectItem key={o.value} value={o.value}>
                                                        {o.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={nested(`modules.${i}.modality_id`)} />
                                    </div>
                                    <div className="grid gap-1 md:col-span-3">
                                        <Label htmlFor={`module-content-${i}`}>Contenu</Label>
                                        <textarea
                                            id={`module-content-${i}`}
                                            rows={3}
                                            value={m.content}
                                            onChange={(e) => setModule(i, { content: e.target.value })}
                                            className={textareaClass}
                                        />
                                        <InputError message={nested(`modules.${i}.content`)} />
                                    </div>
                                    <div className="flex gap-2 md:col-span-3">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            aria-label={`Monter le module ${i + 1}`}
                                            onClick={() => setData('modules', moveItem(data.modules, i, -1))}
                                        >
                                            <ArrowUp className="h-4 w-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            aria-label={`Descendre le module ${i + 1}`}
                                            onClick={() => setData('modules', moveItem(data.modules, i, 1))}
                                        >
                                            <ArrowDown className="h-4 w-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            aria-label={`Supprimer le module ${i + 1}`}
                                            onClick={() =>
                                                setData(
                                                    'modules',
                                                    data.modules.filter((_, j) => j !== i),
                                                )
                                            }
                                        >
                                            <Trash2 className="h-4 w-4" /> Supprimer
                                        </Button>
                                    </div>
                                </div>
                            ))}
                            <InputError message={errors.modules} />
                            {data.modules.length > 0 && (
                                <p className="text-sm">
                                    Total des modules : {hoursText(modulesSum)} h
                                    {Number.isFinite(duration) ? ` (durée de la formation : ${hoursText(duration)} h)` : ''}
                                </p>
                            )}
                            {gap && (
                                <p role="alert" className="rounded-md border border-amber-500 bg-amber-50 p-2 text-sm text-amber-900">
                                    Attention : la somme des modules ({hoursText(modulesSum)} h) ne correspond pas {durationLabel} (
                                    {hoursText(duration)} h). Vous pouvez tout de même enregistrer.
                                </p>
                            )}
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={data.modules.length >= 30}
                                    onClick={() =>
                                        setData('modules', [
                                            ...data.modules,
                                            { uid: `new-${++newModuleCount}`, title: '', duration_hours: '', modality_id: '', content: '' },
                                        ])
                                    }
                                >
                                    <Plus className="h-4 w-4" /> Ajouter un module
                                </Button>{' '}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={data.modules.length === 0 || !Number.isFinite(duration) || duration <= 0}
                                    title="Répartit les heures en centre (sinon la durée totale) entre les modules, par demi-heure ; modifiables ensuite"
                                    onClick={spreadHours}
                                >
                                    Répartir les heures
                                </Button>
                            </div>
                        </fieldset>

                        <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2" aria-label="Méthodes et évaluation">
                            <legend className="px-2 text-sm font-semibold">Méthodes et évaluation</legend>
                            {area('methods', 'Méthodes et moyens pédagogiques')}
                            {area('evaluation', 'Modalités d’évaluation')}
                            {area('sanction', 'Sanction de la formation (attestation, certification…)')}
                        </fieldset>

                        <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2" aria-label="Accessibilité et accès">
                            <legend className="px-2 text-sm font-semibold">Accessibilité et accès</legend>
                            {area('accessibility', 'Accessibilité aux personnes en situation de handicap')}
                            {area('access_terms', 'Modalités et délais d’accès')}
                        </fieldset>

                        <fieldset className="grid gap-2 rounded-xl border p-4" aria-label="Compétences">
                            <legend className="px-2 text-sm font-semibold">Compétences (grilles du livret d’apprentissage)</legend>
                            <p className="text-muted-foreground text-xs">
                                Une ligne par élément : « AT » pour une activité type, « CP » pour une compétence, un tiret pour un savoir-faire.
                                Collez la grille depuis Word puis ajoutez les préfixes.
                            </p>
                            <textarea
                                id="competences_text"
                                rows={12}
                                value={data.competences_text}
                                onChange={(e) => setData('competences_text', e.target.value)}
                                className={`${textareaClass} font-mono text-xs`}
                                placeholder={
                                    'AT Préparer et dresser des entrées et des desserts\nCP Réaliser la mise en place des entrées et des desserts\n- Peser les produits'
                                }
                            />
                            <InputError message={errors.competences_text} />
                        </fieldset>

                        <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2" aria-label="Résultats">
                            <legend className="px-2 text-sm font-semibold">Résultats</legend>
                            {area('results', 'Indicateurs de résultats (satisfaction, réussite…)')}
                        </fieldset>

                        <fieldset className="grid gap-2 rounded-xl border p-4 sm:grid-cols-2">
                            <legend className="px-2 text-sm font-semibold">Financements possibles</legend>
                            {options.funding_mode.map((f) => (
                                <Label key={f.value} className="flex items-center gap-2 font-normal">
                                    <Checkbox
                                        checked={data.funding_mode_ids.includes(f.value)}
                                        onCheckedChange={(v) => toggleFunding(f.value, v === true)}
                                    />
                                    {f.label}
                                </Label>
                            ))}
                            <InputError message={errors.funding_mode_ids ?? itemError(errors, 'funding_mode_ids')} />
                        </fieldset>

                        {editing && (
                            <section aria-label="Plaquettes" className="grid gap-2 rounded-xl border p-4">
                                <h2 className="text-sm font-semibold">Plaquettes</h2>
                                {brochures.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">Aucune plaquette rattachée à cette formation ni à son DAS.</p>
                                ) : (
                                    <ul className="grid gap-1 text-sm">
                                        {brochures.map((b) => (
                                            <li key={b.id} className="flex flex-wrap items-center gap-2">
                                                {can?.brochures ? (
                                                    <a className="underline" href={route('catalogue.brochures.download', b.id)}>
                                                        {b.title}
                                                    </a>
                                                ) : (
                                                    <span>{b.title}</span>
                                                )}
                                                <span className="text-muted-foreground">
                                                    {b.original_name} · {formatSize(b.size)} ·{' '}
                                                    {b.source === 'das' ? 'via le DAS' : 'rattachée à la formation'}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <Link className="text-sm underline" href={route('catalogue.brochures.index')}>
                                    Gérer les plaquettes
                                </Link>
                            </section>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" disabled={processing}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Enregistrer
                            </Button>
                            <Button type="button" variant="outline" asChild>
                                <Link href={route('catalogue.index')}>Annuler</Link>
                            </Button>
                            {editing && training && can?.manage && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={duplicating}
                                    onClick={() => {
                                        if (duplicating) return;
                                        if (
                                            !isDirty ||
                                            window.confirm('Les modifications non enregistrées seront perdues. Dupliquer la formation enregistrée ?')
                                        ) {
                                            router.post(
                                                route('catalogue.duplicate', training.id),
                                                {},
                                                { onStart: () => setDuplicating(true), onFinish: () => setDuplicating(false) },
                                            );
                                        }
                                    }}
                                >
                                    Dupliquer
                                </Button>
                            )}
                        </div>
                    </fieldset>
                </form>
            </div>
        </AppLayout>
    );
}
