import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Member {
    role: string;
    name: string;
    phone: string | null;
    email: string | null;
}

interface BookletProps {
    settings: {
        team: Member[];
        director_note: string | null;
        annexes: { id: string; title: string; size: number }[];
    };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: "Livret d'apprentissage", href: '/admin/livret-apprentissage' }];

const textareaClass = 'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden';

/** Administration › Livret d'apprentissage: the team printed in it, the word of the director and the annexes merged at the end. */
export default function Booklet({ settings }: BookletProps) {
    const { data, setData, put, processing, errors } = useForm({
        team: settings.team.map((m) => ({ ...m, phone: m.phone ?? '', email: m.email ?? '' })),
        director_note: settings.director_note ?? '',
    });
    const err = errors as Record<string, string | undefined>;
    const [annexTitle, setAnnexTitle] = useState('');
    const [annexFile, setAnnexFile] = useState<File | null>(null);
    const [annexErrors, setAnnexErrors] = useState<Record<string, string>>({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/admin/livret-apprentissage', { preserveScroll: true });
    };
    const setMember = (i: number, key: keyof Member, value: string) =>
        setData(
            'team',
            data.team.map((m, j) => (j === i ? { ...m, [key]: value } : m)),
        );

    const addAnnex: FormEventHandler = (e) => {
        e.preventDefault();
        router.post(
            '/admin/livret-apprentissage/annexes',
            { title: annexTitle, file: annexFile },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (e) => setAnnexErrors(e),
                onSuccess: () => {
                    setAnnexTitle('');
                    setAnnexFile(null);
                    setAnnexErrors({});
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Livret d'apprentissage" />
            <div className="flex max-w-4xl flex-col gap-6 p-4">
                <Heading
                    title="Livret d'apprentissage"
                    description="Ce qui est imprimé dans le livret de chaque apprenti (bouton « Livret d'apprentissage (PDF) » de la candidature)."
                />

                <form onSubmit={submit} className="grid gap-4">
                    <fieldset className="grid gap-3 rounded-xl border p-4">
                        <legend className="px-2 text-sm font-semibold">Équipe du centre de formation</legend>
                        <p className="text-muted-foreground text-xs">
                            Responsable de formation, formateur référent, assistante, référent handicap, référent mobilité… Les formateurs des séances
                            s'ajoutent tout seuls.
                        </p>
                        {data.team.map((m, i) => (
                            <div key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_9rem_1fr_auto]">
                                <div>
                                    <Input
                                        aria-label="Rôle"
                                        placeholder="Rôle"
                                        value={m.role}
                                        onChange={(e) => setMember(i, 'role', e.target.value)}
                                    />
                                    <InputError message={err[`team.${i}.role`]} />
                                </div>
                                <div>
                                    <Input
                                        aria-label="Nom"
                                        placeholder="Prénom NOM"
                                        value={m.name}
                                        onChange={(e) => setMember(i, 'name', e.target.value)}
                                    />
                                    <InputError message={err[`team.${i}.name`]} />
                                </div>
                                <Input
                                    aria-label="Téléphone"
                                    placeholder="Téléphone"
                                    value={m.phone ?? ''}
                                    onChange={(e) => setMember(i, 'phone', e.target.value)}
                                />
                                <div>
                                    <Input
                                        aria-label="E-mail"
                                        placeholder="E-mail"
                                        value={m.email ?? ''}
                                        onChange={(e) => setMember(i, 'email', e.target.value)}
                                    />
                                    <InputError message={err[`team.${i}.email`]} />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Retirer la ligne"
                                    onClick={() =>
                                        setData(
                                            'team',
                                            data.team.filter((_, j) => j !== i),
                                        )
                                    }
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            </div>
                        ))}
                        <InputError message={err.team} />
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setData('team', [...data.team, { role: '', name: '', phone: '', email: '' }])}
                            >
                                <Plus className="h-4 w-4" /> Ajouter une personne
                            </Button>
                        </div>
                    </fieldset>

                    <fieldset className="grid gap-2 rounded-xl border p-4">
                        <legend className="px-2 text-sm font-semibold">Mot de la direction (page de garde)</legend>
                        <textarea
                            id="director_note"
                            rows={3}
                            value={data.director_note}
                            onChange={(e) => setData('director_note', e.target.value)}
                            className={textareaClass}
                            placeholder="Pour l'équipe pédagogique, Prénom NOM, directeur général"
                        />
                        <InputError message={err.director_note} />
                    </fieldset>

                    <div>
                        <Button type="submit" disabled={processing}>
                            Enregistrer
                        </Button>
                    </div>
                </form>

                <section className="grid gap-3 rounded-xl border p-4">
                    <h2 className="text-sm font-semibold">Annexes (PDF ajoutés tels quels)</h2>
                    <p className="text-muted-foreground text-xs">
                        Statut de l'apprenti, règlement intérieur, règlement du CFA, engagements réciproques… Ils s'insèrent après le planning, avant
                        les pages de suivi, dans l'ordre de cette liste. PDF de 20 Mo au plus.
                    </p>
                    {settings.annexes.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Aucune annexe.</p>
                    ) : (
                        <ol className="grid gap-1 text-sm">
                            {settings.annexes.map((a, i) => (
                                <li key={a.id} className="flex items-center justify-between gap-2 rounded border px-3 py-2">
                                    <span>
                                        {i + 1}. {a.title} <span className="text-muted-foreground text-xs">({Math.ceil(a.size / 1024)} Ko)</span>
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            confirm(`Retirer l'annexe « ${a.title} » ?`) &&
                                            router.delete(`/admin/livret-apprentissage/annexes/${a.id}`, { preserveScroll: true })
                                        }
                                    >
                                        Retirer
                                    </Button>
                                </li>
                            ))}
                        </ol>
                    )}
                    <form onSubmit={addAnnex} className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <div className="grid gap-1">
                            <Label htmlFor="annex_title">Titre</Label>
                            <Input
                                id="annex_title"
                                value={annexTitle}
                                onChange={(e) => setAnnexTitle(e.target.value)}
                                placeholder="Règlement intérieur"
                            />
                            <InputError message={annexErrors.title} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="annex_file">Fichier PDF</Label>
                            <Input id="annex_file" type="file" accept="application/pdf" onChange={(e) => setAnnexFile(e.target.files?.[0] ?? null)} />
                            <InputError message={annexErrors.file} />
                        </div>
                        <Button type="submit" variant="outline">
                            Ajouter l'annexe
                        </Button>
                    </form>
                </section>
            </div>
        </AppLayout>
    );
}
