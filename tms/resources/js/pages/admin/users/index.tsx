import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';

interface UserRow {
    id: string;
    name: string;
    email: string;
    active: boolean;
    activated: boolean;
    last_login_at: string | null;
}

interface InvitationRow {
    id: string;
    email: string;
    name: string;
    role: string;
    expires_at: string;
    expired: boolean;
}

interface UsersIndexProps {
    users: UserRow[];
    invitations: InvitationRow[];
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Utilisateurs', href: '/admin/users' }];

const dateTime = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Europe/Paris' });

function formatDate(value: string | null): string {
    return value ? dateTime.format(new Date(value)) : '—';
}

function UserStatus({ user }: { user: UserRow }) {
    if (!user.active) {
        return <Badge variant="destructive">Désactivé</Badge>;
    }

    if (!user.activated) {
        return <Badge variant="outline">En attente d'activation</Badge>;
    }

    return <Badge variant="secondary">Actif</Badge>;
}

export default function UsersIndex({ users, invitations, status }: UsersIndexProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Utilisateurs" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading title="Utilisateurs" description="Comptes de votre organisme et invitations en attente" />
                    <Button asChild>
                        <Link href={route('admin.users.invite')}>
                            <UserPlus className="h-4 w-4" />
                            Inviter un utilisateur
                        </Link>
                    </Button>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <section className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-2 font-medium">Nom</th>
                                <th className="px-4 py-2 font-medium">Adresse e-mail</th>
                                <th className="px-4 py-2 font-medium">Statut</th>
                                <th className="px-4 py-2 font-medium">Dernière connexion</th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.map((user) => (
                                <tr key={user.id} className="border-t">
                                    <td className="px-4 py-2">{user.name}</td>
                                    <td className="px-4 py-2">{user.email}</td>
                                    <td className="px-4 py-2">
                                        <UserStatus user={user} />
                                    </td>
                                    <td className="px-4 py-2">{formatDate(user.last_login_at)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <section className="space-y-2">
                    <h3 className="text-base font-semibold">Invitations en attente</h3>
                    {invitations.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Aucune invitation en attente.</p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">Nom</th>
                                        <th className="px-4 py-2 font-medium">Adresse e-mail</th>
                                        <th className="px-4 py-2 font-medium">Rôle</th>
                                        <th className="px-4 py-2 font-medium">Expiration</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {invitations.map((invitation) => (
                                        <tr key={invitation.id} className="border-t">
                                            <td className="px-4 py-2">{invitation.name}</td>
                                            <td className="px-4 py-2">{invitation.email}</td>
                                            <td className="px-4 py-2">{invitation.role}</td>
                                            <td className="px-4 py-2">
                                                {invitation.expired ? <Badge variant="destructive">Expirée</Badge> : formatDate(invitation.expires_at)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
