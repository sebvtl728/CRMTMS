import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface RoleOption {
    value: string;
    label: string;
}

type InviteForm = {
    email: string;
    name: string;
    role: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Utilisateurs', href: '/admin/users' },
    { title: 'Inviter', href: '/admin/users/invite' },
];

export default function InviteUser({ roles, defaults }: { roles: RoleOption[]; defaults?: InviteForm }) {
    const { data, setData, post, processing, errors } = useForm<InviteForm>({
        email: defaults?.email ?? '',
        name: defaults?.name ?? '',
        // Prefilled from another page (a trainer): only a role that exists.
        role: roles.some((r) => r.value === defaults?.role) ? (defaults?.role ?? '') : '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.users.invitations.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inviter un utilisateur" />

            <div className="max-w-xl p-4">
                <Heading
                    title="Inviter un utilisateur"
                    description="La personne reçoit un lien d'activation valable 24 heures pour choisir son mot de passe."
                />

                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Nom complet</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoComplete="off" />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Adresse e-mail</Label>
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            required
                            autoComplete="off"
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="role">Rôle</Label>
                        <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                            <SelectTrigger id="role">
                                <SelectValue placeholder="Choisir un rôle" />
                            </SelectTrigger>
                            <SelectContent>
                                {roles.map((role) => (
                                    <SelectItem key={role.value} value={role.value}>
                                        {role.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.role} />
                    </div>

                    <div className="flex items-center gap-4">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Envoyer l'invitation
                        </Button>
                        <Link href={route('admin.users.index')} className="text-muted-foreground text-sm underline-offset-4 hover:underline">
                            Annuler
                        </Link>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
