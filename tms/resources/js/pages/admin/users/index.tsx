import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Copy, UserPlus } from 'lucide-react';
import { useState } from 'react';

interface UserRow {
    id: string;
    name: string;
    email: string;
    active: boolean;
    activated: boolean;
    last_login_at: string | null;
    role: string | null;
    das_ids: string[];
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
    dasOptions: { value: string; label: string }[];
    invitations: InvitationRow[];
    status?: string;
    warning?: string;
    activationLink?: string;
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

// Shown once when the invitation e-mail could not be sent: the administrator passes the link on.
function ActivationLinkNotice({ warning, link }: { warning: string; link?: string }) {
    const [copied, setCopied] = useState(false);

    const copy = () => {
        if (!link) return;
        navigator.clipboard?.writeText(link).then(
            () => setCopied(true),
            () => setCopied(false),
        );
    };

    return (
        <div
            role="alert"
            className="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
        >
            <p className="font-medium">{warning}</p>
            {link && (
                <div className="flex flex-col gap-2 sm:flex-row">
                    <Input
                        readOnly
                        value={link}
                        aria-label="Lien d'activation"
                        onFocus={(e) => e.currentTarget.select()}
                        className="font-mono text-xs"
                    />
                    <Button type="button" variant="outline" onClick={copy}>
                        <Copy className="h-4 w-4" />
                        {copied ? 'Copié' : 'Copier le lien'}
                    </Button>
                </div>
            )}
            <p className="text-xs">Valable 24 heures, usage unique. Ne le transmettez qu'à la personne invitée.</p>
        </div>
    );
}

// « DAS suivis » of a Responsable DAS: what he sees in the CRM.
function DasDialog({ user, options, onClose }: { user: UserRow | null; options: { value: string; label: string }[]; onClose: () => void }) {
    const [selected, setSelected] = useState<string[]>(user?.das_ids ?? []);
    const [saving, setSaving] = useState(false);

    const toggle = (id: string, checked: boolean) => setSelected((current) => (checked ? [...current, id] : current.filter((d) => d !== id)));

    return (
        <Dialog open={user !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>DAS suivis</DialogTitle>
                    <DialogDescription>{user?.name} ne verra dans le CRM que les entreprises rattachées à ces DAS.</DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    {options.map((o) => (
                        <Label key={o.value} className="flex items-center gap-2 font-normal">
                            <Checkbox checked={selected.includes(o.value)} onCheckedChange={(c) => toggle(o.value, c === true)} />
                            {o.label}
                        </Label>
                    ))}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button
                        disabled={saving}
                        onClick={() => {
                            setSaving(true);
                            router.put(
                                route('admin.users.das', user!.id),
                                { das_ids: selected },
                                {
                                    preserveScroll: true,
                                    onFinish: () => {
                                        setSaving(false);
                                        onClose();
                                    },
                                },
                            );
                        }}
                    >
                        Enregistrer
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function UsersIndex({ users, dasOptions, invitations, status, warning, activationLink }: UsersIndexProps) {
    const [dasUser, setDasUser] = useState<UserRow | null>(null);
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
                {warning && <ActivationLinkNotice warning={warning} link={activationLink} />}

                <section className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-2 font-medium">Nom</th>
                                <th className="px-4 py-2 font-medium">Adresse e-mail</th>
                                <th className="px-4 py-2 font-medium">Statut</th>
                                <th className="px-4 py-2 font-medium">Dernière connexion</th>
                                <th className="px-4 py-2 font-medium">DAS suivis</th>
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
                                    <td className="px-4 py-2">
                                        {user.role === 'responsable_das' ? (
                                            <Button type="button" variant="outline" size="sm" onClick={() => setDasUser(user)}>
                                                {user.das_ids.length === 0
                                                    ? 'Aucun (à définir)'
                                                    : dasOptions
                                                          .filter((o) => user.das_ids.includes(o.value))
                                                          .map((o) => o.label)
                                                          .join(', ')}
                                            </Button>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
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
                                                {invitation.expired ? (
                                                    <Badge variant="destructive">Expirée</Badge>
                                                ) : (
                                                    formatDate(invitation.expires_at)
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
            {dasUser && <DasDialog key={dasUser.id} user={dasUser} options={dasOptions} onClose={() => setDasUser(null)} />}
        </AppLayout>
    );
}
