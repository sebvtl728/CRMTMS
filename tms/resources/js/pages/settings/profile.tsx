import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profil',
        href: '/settings/profile',
    },
];

export default function Profile({ mustVerifyEmail, status, daily_digest }: { mustVerifyEmail: boolean; status?: string; daily_digest: boolean }) {
    const { auth } = usePage<SharedData>().props;

    const { data, setData, patch, errors, processing, recentlySuccessful, reset } = useForm({
        name: auth.user.name,
        email: auth.user.email,
        current_password: '',
        daily_digest,
    });

    // The e-mail is the login identity: changing it needs the current password.
    const emailChanged = data.email.trim().toLowerCase() !== auth.user.email;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('profile.update'), {
            preserveScroll: true,
            onFinish: () => reset('current_password'),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profil" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Informations du profil" description="Modifiez votre nom et votre adresse e-mail" />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nom</Label>

                            <Input
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                autoComplete="name"
                                placeholder="Prénom Nom"
                            />

                            <InputError className="mt-2" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Adresse e-mail</Label>

                            <Input
                                id="email"
                                type="email"
                                className="mt-1 block w-full"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                                autoComplete="username"
                                placeholder="adresse@exemple.fr"
                            />

                            <InputError className="mt-2" message={errors.email} />
                        </div>

                        {auth.permissions.includes('crm.activities.view') && (
                            <div className="grid gap-2">
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="daily_digest"
                                        checked={data.daily_digest}
                                        onCheckedChange={(checked) => setData('daily_digest', checked === true)}
                                    />
                                    <Label htmlFor="daily_digest">Recevoir le récapitulatif quotidien</Label>
                                </div>
                                <p className="text-muted-foreground text-sm">
                                    Chaque matin à 7 h 30, un e-mail liste vos actions en retard et du jour (aucun e-mail s'il n'y en a pas).
                                </p>
                                <InputError message={errors.daily_digest} />
                            </div>
                        )}

                        {emailChanged && (
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">Mot de passe actuel</Label>

                                <Input
                                    id="current_password"
                                    type="password"
                                    className="mt-1 block w-full"
                                    value={data.current_password}
                                    onChange={(e) => setData('current_password', e.target.value)}
                                    required
                                    autoComplete="current-password"
                                />
                                <p className="text-muted-foreground text-sm">Requis pour changer l'adresse e-mail de connexion.</p>

                                <InputError className="mt-2" message={errors.current_password} />
                            </div>
                        )}

                        {mustVerifyEmail && auth.user.email_verified_at === null && (
                            <div>
                                <p className="mt-2 text-sm text-neutral-800">
                                    Votre adresse e-mail n'est pas vérifiée.{' '}
                                    <Link
                                        href={route('verification.send')}
                                        method="post"
                                        as="button"
                                        className="rounded-md text-sm text-neutral-600 underline hover:text-neutral-900 focus:ring-2 focus:ring-offset-2 focus:outline-hidden"
                                    >
                                        Renvoyer l'e-mail de vérification.
                                    </Link>
                                </p>

                                {status === 'verification-link-sent' && (
                                    <div className="mt-2 text-sm font-medium text-green-600">
                                        Un nouveau lien de vérification a été envoyé à votre adresse e-mail.
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Enregistrer</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Enregistré</p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
