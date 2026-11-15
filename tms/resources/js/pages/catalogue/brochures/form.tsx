import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatSize, itemError, type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Version {
    id: string;
    original_name: string;
    size: number;
    created_at: string | null;
    current: boolean;
}

interface FormProps {
    brochure: { id: string; title: string; training_ids: string[]; das_ids: string[]; versions: Version[] } | null;
    options: { das: Option[]; trainings: Option[] };
}

type BrochureForm = {
    title: string;
    file: File | null;
    training_ids: string[];
    das_ids: string[];
};

export default function BrochureFormPage({ brochure, options }: FormProps) {
    const editing = brochure !== null;
    const [filter, setFilter] = useState('');

    const { data, setData, post, processing, errors } = useForm<BrochureForm & { _method?: string }>({
        title: brochure?.title ?? '',
        file: null,
        training_ids: brochure?.training_ids ?? [],
        das_ids: brochure?.das_ids ?? [],
    });

    const title = editing ? 'Modifier la plaquette' : 'Nouvelle plaquette';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Catalogue', href: '/catalogue' },
        { title: 'Plaquettes', href: '/catalogue/brochures' },
        { title: editing ? 'Modifier' : 'Nouvelle plaquette', href: '#' },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // A file travels in a multipart POST: the update is spoofed as a PUT.
        if (editing) {
            setData('_method', 'put');
            post(route('catalogue.brochures.update', brochure.id), { preserveScroll: true, forceFormData: true });
        } else {
            post(route('catalogue.brochures.store'), { preserveScroll: true, forceFormData: true });
        }
    };

    const toggle = (key: 'training_ids' | 'das_ids', id: string, checked: boolean) =>
        setData(key, checked ? [...data[key], id] : data[key].filter((v) => v !== id));

    const needle = filter.trim().toLowerCase();
    const trainings = options.trainings.filter((t) => needle === '' || t.label.toLowerCase().includes(needle) || data.training_ids.includes(t.value));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading title={title} description="Un PDF de 10 Mo au plus, rattaché à des formations et/ou à des DAS." />

                <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
                    <fieldset className="grid gap-4 rounded-xl border p-4">
                        <legend className="px-2 text-sm font-semibold">Plaquette</legend>
                        <div className="grid gap-2">
                            <Label htmlFor="title">Titre *</Label>
                            <Input
                                id="title"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                maxLength={200}
                                required
                                autoFocus
                                placeholder="Ex. Plaquette HACCP"
                            />
                            <InputError message={errors.title} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="file">{editing ? 'Remplacer le fichier (nouvelle version)' : 'Fichier PDF *'}</Label>
                            <Input id="file" type="file" accept="application/pdf,.pdf" onChange={(e) => setData('file', e.target.files?.[0] ?? null)} />
                            <InputError message={errors.file} />
                        </div>
                    </fieldset>

                    <fieldset className="grid gap-2 rounded-xl border p-4 sm:grid-cols-2">
                        <legend className="px-2 text-sm font-semibold">DAS concernés (toutes leurs formations)</legend>
                        {options.das.map((d) => (
                            <Label key={d.value} className="flex items-center gap-2 font-normal">
                                <Checkbox checked={data.das_ids.includes(d.value)} onCheckedChange={(v) => toggle('das_ids', d.value, v === true)} />
                                {d.label}
                            </Label>
                        ))}
                        <InputError message={errors.das_ids ?? itemError(errors, 'das_ids')} />
                    </fieldset>

                    <fieldset className="grid gap-2 rounded-xl border p-4">
                        <legend className="px-2 text-sm font-semibold">Formations concernées</legend>
                        <Input
                            type="search"
                            aria-label="Filtrer les formations"
                            placeholder="Filtrer les formations"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                        />
                        <div className="grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-2">
                            {trainings.map((t) => (
                                <Label key={t.value} className="flex items-center gap-2 font-normal">
                                    <Checkbox
                                        checked={data.training_ids.includes(t.value)}
                                        onCheckedChange={(v) => toggle('training_ids', t.value, v === true)}
                                    />
                                    {t.label}
                                </Label>
                            ))}
                            {trainings.length === 0 && <p className="text-muted-foreground text-sm">Aucune formation.</p>}
                        </div>
                        <InputError message={errors.training_ids ?? itemError(errors, 'training_ids')} />
                    </fieldset>

                    {editing && (
                        <section aria-label="Versions" className="grid gap-2 rounded-xl border p-4">
                            <h2 className="text-sm font-semibold">Versions du fichier</h2>
                            <ul className="grid gap-1 text-sm">
                                {brochure.versions.map((v) => (
                                    <li key={v.id} className="flex flex-wrap items-center gap-2">
                                        <a className="underline" href={route('catalogue.brochures.versions.download', [brochure.id, v.id])}>
                                            {v.original_name}
                                        </a>
                                        <span className="text-muted-foreground">
                                            {formatSize(v.size)} · {formatDateTime(v.created_at)}
                                        </span>
                                        {v.current && <span className="font-medium">(version actuelle)</span>}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Enregistrer
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href={route('catalogue.brochures.index')}>Annuler</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
