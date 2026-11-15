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
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface FormProps {
    training: {
        id: string;
        code: string | null;
        title: string;
        das_id: string;
        duration_hours: string | null;
        duration_days: string | null;
        audience: string | null;
        prerequisites: string | null;
        price_company: string | null;
        price_individual: string | null;
        is_active: boolean;
        funding_mode_ids: string[];
    } | null;
    options: { das: Option[]; funding_mode: Option[] };
    brochures?: { id: string; title: string; original_name: string; size: number; source: 'training' | 'das' }[];
    can?: { brochures: boolean };
}

type TrainingForm = {
    code: string;
    title: string;
    das_id: string;
    duration_hours: string;
    duration_days: string;
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

const textareaClass = 'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden';

export default function TrainingFormPage({ training, options, brochures = [], can }: FormProps) {
    const editing = training !== null;

    const { data, setData, post, put, processing, errors } = useForm<TrainingForm>({
        code: training?.code ?? '',
        title: training?.title ?? '',
        das_id: training?.das_id ?? '',
        duration_hours: decimalInput(training?.duration_hours),
        duration_days: decimalInput(training?.duration_days),
        audience: training?.audience ?? '',
        prerequisites: training?.prerequisites ?? '',
        price_company: amountInput(training?.price_company),
        price_individual: amountInput(training?.price_individual),
        funding_mode_ids: training?.funding_mode_ids ?? [],
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

    const text = (key: Exclude<keyof TrainingForm, 'funding_mode_ids'>, label: string, props: Record<string, unknown> = {}) => (
        <div className="grid gap-2">
            <Label htmlFor={key}>{label}</Label>
            <Input id={key} value={data[key]} onChange={(e) => setData(key, e.target.value)} {...props} />
            <InputError message={errors[key]} />
        </div>
    );

    const toggleFunding = (id: string, checked: boolean) =>
        setData('funding_mode_ids', checked ? [...data.funding_mode_ids, id] : data.funding_mode_ids.filter((f) => f !== id));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title={title}
                    description={editing && !training.is_active ? 'Formation désactivée : elle n’est plus proposée.' : undefined}
                />

                <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
                    <fieldset className="grid gap-4 rounded-xl border p-4 md:grid-cols-2">
                        <legend className="px-2 text-sm font-semibold">Formation</legend>
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
                        {text('duration_hours', 'Durée (heures)', { inputMode: 'decimal', placeholder: '21' })}
                        {text('duration_days', 'Durée (jours)', { inputMode: 'decimal', placeholder: '3' })}
                        {text('price_company', 'Tarif entreprise HT par stagiaire (€)', { inputMode: 'decimal', placeholder: '1 250,00' })}
                        {text('price_individual', 'Tarif particulier HT par stagiaire (€)', { inputMode: 'decimal', placeholder: '990,00' })}
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
                                                {b.original_name} · {formatSize(b.size)} · {b.source === 'das' ? 'via le DAS' : 'rattachée à la formation'}
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
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
