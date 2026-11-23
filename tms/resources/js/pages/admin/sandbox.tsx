import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { RotateCcw, TriangleAlert } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Bac à sable', href: '/admin/sandbox' }];

/** Administration › Bac à sable: reset the local sandbox (demo), never on a server. */
export default function Sandbox() {
    const { data, setData, post, processing, errors, reset } = useForm({ mode: 'work', confirmation: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.sandbox.reset'), { preserveScroll: true, onSuccess: () => reset('confirmation') });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bac à sable" />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Bac à sable"
                    description="Ce TMS est un bac à sable local (démonstration et essais). Cette page n'existe jamais sur un serveur."
                />

                <form onSubmit={submit} className="flex max-w-xl flex-col gap-5">
                    <fieldset className="grid gap-3">
                        <legend className="mb-1 text-sm font-medium">Que faut-il effacer ?</legend>
                        <label className="flex items-start gap-3 text-sm">
                            <input
                                type="radio"
                                name="mode"
                                className="mt-1"
                                checked={data.mode === 'work'}
                                onChange={() => setData('mode', 'work')}
                            />
                            <span>
                                <span className="font-medium">Effacer les données de travail</span>
                                <span className="text-muted-foreground block">
                                    Fiches, opportunités, devis, catalogue, sessions, dossiers, factures, documents, candidatures, actions et
                                    fichiers. Les comptes, rôles, référentiels, modèles et connecteurs sont gardés.
                                </span>
                            </span>
                        </label>
                        <label className="flex items-start gap-3 text-sm">
                            <input type="radio" name="mode" className="mt-1" checked={data.mode === 'all'} onChange={() => setData('mode', 'all')} />
                            <span>
                                <span className="font-medium">Repartir de zéro</span>
                                <span className="text-muted-foreground block">
                                    Tout est effacé : organisme neuf avec les réglages par défaut. Seul votre compte est gardé (même mot de passe).
                                </span>
                            </span>
                        </label>
                        <InputError message={errors.mode} />
                    </fieldset>

                    <p className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                        <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
                        L'organisme devient « Organisme de démonstration » et sa société une entreprise fictive. L'effacement est définitif.
                    </p>

                    <div className="grid gap-2">
                        <Label htmlFor="confirmation">Tapez RÉINITIALISER pour confirmer</Label>
                        <Input
                            id="confirmation"
                            value={data.confirmation}
                            onChange={(e) => setData('confirmation', e.target.value)}
                            autoComplete="off"
                        />
                        <InputError message={errors.confirmation} />
                    </div>

                    <Button type="submit" variant="destructive" disabled={processing || data.confirmation === ''} className="self-start">
                        <RotateCcw className="h-4 w-4" />
                        Réinitialiser le bac à sable
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
