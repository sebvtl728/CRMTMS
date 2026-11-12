import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface SecurityProps {
    settings: { force_2fa: boolean; idle_timeout_minutes: number };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sécurité', href: '/admin/security' }];

export default function Security({ settings, status }: SecurityProps) {
    const { data, setData, put, processing, errors } = useForm({
        force_2fa: settings.force_2fa,
        idle_timeout_minutes: String(settings.idle_timeout_minutes),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.security.update'), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sécurité" />

            <div className="flex flex-col gap-6 p-4">
                <Heading title="Sécurité" description="Règles de connexion et de session appliquées à tous les comptes de votre organisme" />

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <form onSubmit={submit} className="flex max-w-xl flex-col gap-6">
                    <div className="flex items-start gap-3">
                        <Checkbox id="force_2fa" checked={data.force_2fa} onCheckedChange={(checked) => setData('force_2fa', checked === true)} />
                        <div className="grid gap-1">
                            <Label htmlFor="force_2fa">Imposer la double authentification à tous les comptes</Label>
                            <p className="text-muted-foreground text-sm">
                                Elle est déjà obligatoire pour les administrateurs et la direction. Chaque compte devra la configurer à sa prochaine
                                connexion.
                            </p>
                            <InputError message={errors.force_2fa} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="idle_timeout_minutes">Déconnexion après inactivité (minutes)</Label>
                        <Input
                            id="idle_timeout_minutes"
                            type="number"
                            min={15}
                            max={480}
                            step={1}
                            className="w-40"
                            value={data.idle_timeout_minutes}
                            onChange={(e) => setData('idle_timeout_minutes', e.target.value)}
                        />
                        <p className="text-muted-foreground text-sm">Entre 15 et 480 minutes (120 par défaut).</p>
                        <InputError message={errors.idle_timeout_minutes} />
                    </div>

                    <div>
                        <Button type="submit" disabled={processing}>
                            Enregistrer
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
