import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Lock, MoreHorizontal, Plus } from 'lucide-react';
import { useState } from 'react';

type ScopeValue = 'own' | 'das' | 'all' | 'trainer';

interface RoleColumn {
    id: string;
    label: string;
    system: boolean;
    admin: boolean;
    requires_2fa: boolean;
    /** Double authentification always required (administrator, direction). */
    locked_2fa: boolean;
    users_count: number;
    permissions: string[];
    /** Default rights of a system role (empty for a custom role). */
    defaults: string[];
    scopes: Record<string, ScopeValue>;
    /** Default scopes of a system role (empty for a custom role). */
    default_scopes: Record<string, ScopeValue>;
    /** Sensitive right held without mandatory double authentification (non-blocking). */
    two_factor_warning: string | null;
}

interface PermissionRow {
    name: string;
    label: string;
    description: string;
    scoped: boolean;
    /** The scopes offered for this right (« Ses séances » for the séances and attendance only). */
    scope_options: ScopeValue[];
    /** Reserved to the administrator, never editable here. */
    locked: boolean;
    /** Administration-level power, worth a second thought before granting it. */
    warning: string | null;
}

interface RolesProps {
    roles: RoleColumn[];
    groups: { label: string; permissions: PermissionRow[] }[];
    scopeOptions: { value: ScopeValue; label: string }[];
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Rôles et droits', href: '/admin/roles' }];

const NONE = 'none';

function NewRoleDialog({ open, roles, onClose }: { open: boolean; roles: RoleColumn[]; onClose: () => void }) {
    const [label, setLabel] = useState('');
    const [copyFrom, setCopyFrom] = useState(NONE);
    const [requires2fa, setRequires2fa] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setError(null);
        router.post(
            route('admin.roles.store'),
            { label, requires_2fa: requires2fa, copy_from: copyFrom === NONE ? null : copyFrom },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setLabel('');
                    setCopyFrom(NONE);
                    setRequires2fa(true);
                    onClose();
                },
                onError: (errors) => {
                    setError(Object.values(errors)[0] ?? 'Création impossible.');
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Nouveau rôle</DialogTitle>
                        <DialogDescription>
                            Le rôle pourra ensuite être attribué à des utilisateurs depuis la page « Utilisateurs ».
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="role-label">Libellé</Label>
                        <Input id="role-label" value={label} onChange={(e) => setLabel(e.target.value)} maxLength={80} required />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="role-copy">Partir des droits de</Label>
                        <Select value={copyFrom} onValueChange={setCopyFrom}>
                            <SelectTrigger id="role-copy">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>Aucun droit</SelectItem>
                                {roles.map((r) => (
                                    <SelectItem key={r.id} value={r.id}>
                                        {r.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <Label className="flex items-center gap-2 font-normal">
                        <Checkbox checked={requires2fa} onCheckedChange={(c) => setRequires2fa(c === true)} />
                        Double authentification obligatoire
                    </Label>
                    {error && (
                        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={saving}>
                            Créer le rôle
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RenameDialog({ role, onClose }: { role: RoleColumn | null; onClose: () => void }) {
    const [label, setLabel] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setError(null);
        router.patch(
            route('admin.roles.modify', role!.id),
            { label },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (errors) => setError(Object.values(errors)[0] ?? 'Enregistrement impossible.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={role !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                onOpenAutoFocus={() => {
                    setLabel(role?.label ?? '');
                    setError(null);
                }}
            >
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Renommer le rôle</DialogTitle>
                        <DialogDescription>Le nouveau libellé s'affiche partout où le rôle est cité.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="rename-label">Libellé</Label>
                        <Input id="rename-label" value={label} onChange={(e) => setLabel(e.target.value)} maxLength={80} required />
                    </div>
                    {error && (
                        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={saving}>
                            Renommer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Roles({ roles, groups, scopeOptions }: RolesProps) {
    const [saving, setSaving] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);
    const [renaming, setRenaming] = useState<RoleColumn | null>(null);

    // Every change goes through here: the server's message (deletion refused, duplicate label...) is shown as is.
    const act = (method: 'post' | 'patch' | 'delete', url: string, data: Record<string, unknown>, key: string | null = null) => {
        setError(null);
        setSaving(key);
        const options = {
            preserveScroll: true,
            preserveState: true,
            onError: (errors: Record<string, string>) => setError(Object.values(errors)[0] ?? 'Action impossible.'),
            onFinish: () => setSaving(null),
        };
        if (method === 'delete') router.delete(url, options);
        else router[method](url, data as never, options);
    };

    const toggle = (role: RoleColumn, permission: string, granted: boolean) =>
        act('post', route('admin.roles.update', role.id), { permission, granted }, `${role.id}:${permission}`);
    const setScope = (role: RoleColumn, permission: string, scope: string) =>
        act('post', route('admin.roles.update', role.id), { permission, scope }, `${role.id}:${permission}`);
    const set2fa = (role: RoleColumn, required: boolean) =>
        act('patch', route('admin.roles.modify', role.id), { requires_2fa: required }, `${role.id}:2fa`);

    const reset = (role: RoleColumn) => {
        const message = role.system
            ? `Revenir aux droits par défaut du rôle ${role.label} ? Les droits et périmètres modifiés pour ce rôle seront annulés.`
            : `Retirer tous les droits du rôle ${role.label} ?`;
        if (window.confirm(message)) act('post', route('admin.roles.reset', role.id), {});
    };

    const remove = (role: RoleColumn) => {
        if (window.confirm(`Supprimer le rôle ${role.label} ? Cette action est définitive.`))
            act('delete', route('admin.roles.destroy', role.id), {});
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Rôles et droits" />

            <div className="flex min-w-0 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Rôles et droits"
                        description="Ce que chaque rôle peut faire dans votre organisme. Chaque case est enregistrée dès que vous la cochez ou la décochez."
                    />
                    <Button onClick={() => setCreating(true)}>
                        <Plus className="h-4 w-4" />
                        Nouveau rôle
                    </Button>
                </div>

                {error && (
                    <div role="alert" className="text-sm font-medium text-red-600 dark:text-red-400">
                        {error}
                    </div>
                )}

                <ul className="text-muted-foreground list-disc space-y-1 pl-5 text-sm">
                    <li>Le rôle Administrateur de l'organisme a toujours tous les droits : il ne se modifie pas.</li>
                    <li>
                        « Gérer les utilisateurs », « Gérer les rôles et droits » et « Gérer les connecteurs » restent réservés à l'administrateur.
                    </li>
                    <li>
                        Pour un droit à périmètre, la liste règle jusqu'où il porte : « Ses fiches » (celles dont l'utilisateur est responsable ou
                        qu'il a créées), « Ses DAS » ou « Tout l'organisme ».
                    </li>
                    <li>
                        Une case <span className="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-100">surlignée</span>{' '}
                        diffère des droits ou du périmètre par défaut du rôle.
                    </li>
                    <li>La double authentification reste obligatoire pour l'administrateur et la direction, quels que soient leurs droits.</li>
                </ul>

                <TooltipProvider>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-max text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th scope="col" className="bg-muted sticky left-0 z-10 w-64 min-w-64 px-3 py-2 text-left font-medium">
                                        Droit
                                    </th>
                                    {roles.map((role) => (
                                        <th key={role.id} scope="col" className="w-48 min-w-48 px-2 py-2 text-center align-top font-medium">
                                            <div className="flex items-start justify-center gap-1">
                                                <div>
                                                    <div>{role.label}</div>
                                                    <div className="text-muted-foreground text-xs font-normal">
                                                        {role.users_count} utilisateur{role.users_count > 1 ? 's' : ''}
                                                    </div>
                                                </div>
                                                {!role.admin && (
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger asChild>
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-7 w-7"
                                                                aria-label={`Actions du rôle ${role.label}`}
                                                            >
                                                                <MoreHorizontal className="h-4 w-4" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent align="end">
                                                            <DropdownMenuItem onSelect={() => setRenaming(role)}>Renommer</DropdownMenuItem>
                                                            <DropdownMenuItem onSelect={() => reset(role)}>
                                                                Revenir aux droits par défaut
                                                            </DropdownMenuItem>
                                                            {!role.system && (
                                                                <>
                                                                    <DropdownMenuSeparator />
                                                                    <DropdownMenuItem
                                                                        className="text-red-600 focus:text-red-600"
                                                                        onSelect={() => remove(role)}
                                                                    >
                                                                        Supprimer
                                                                    </DropdownMenuItem>
                                                                </>
                                                            )}
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                )}
                                            </div>
                                            <div className="mt-2 flex items-center justify-center gap-2 text-xs font-normal">
                                                {role.locked_2fa || role.admin ? (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <button
                                                                type="button"
                                                                role="switch"
                                                                aria-checked
                                                                aria-label={`Double authentification obligatoire - ${role.label}`}
                                                                aria-disabled
                                                                className="bg-primary relative inline-flex h-5 w-9 shrink-0 cursor-not-allowed items-center rounded-full opacity-60"
                                                            >
                                                                <span className="bg-background ml-[18px] block h-4 w-4 rounded-full" />
                                                            </button>
                                                        </TooltipTrigger>
                                                        <TooltipContent>
                                                            La double authentification est toujours obligatoire pour ce rôle.
                                                        </TooltipContent>
                                                    </Tooltip>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        role="switch"
                                                        aria-checked={role.requires_2fa}
                                                        aria-label={`Double authentification obligatoire - ${role.label}`}
                                                        disabled={saving === `${role.id}:2fa`}
                                                        onClick={() => set2fa(role, !role.requires_2fa)}
                                                        className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors ${role.requires_2fa ? 'bg-primary' : 'bg-input'}`}
                                                    >
                                                        <span
                                                            className={`bg-background block h-4 w-4 rounded-full transition-transform ${role.requires_2fa ? 'translate-x-[18px]' : 'translate-x-0.5'}`}
                                                        />
                                                    </button>
                                                )}
                                                <span className="text-muted-foreground text-left">Double authentification obligatoire</span>
                                            </div>
                                            {role.two_factor_warning && (
                                                <div className="mt-1 text-left text-xs font-normal text-amber-700 dark:text-amber-300">
                                                    ⚠ {role.two_factor_warning}
                                                </div>
                                            )}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            {groups.map((group) => (
                                <tbody key={group.label}>
                                    <tr className="border-t">
                                        <th
                                            colSpan={roles.length + 1}
                                            scope="colgroup"
                                            className="bg-muted/30 sticky left-0 px-3 py-2 text-left text-xs font-semibold uppercase"
                                        >
                                            {group.label}
                                        </th>
                                    </tr>
                                    {group.permissions.map((p) => (
                                        <tr key={p.name} className="border-t">
                                            <th
                                                scope="row"
                                                className="bg-background sticky left-0 z-10 w-64 min-w-64 px-3 py-2 text-left font-normal"
                                            >
                                                <div className="font-medium">{p.label}</div>
                                                <div className="text-muted-foreground text-xs">{p.description}</div>
                                                {p.warning && <div className="mt-1 text-xs text-amber-700 dark:text-amber-300">⚠ {p.warning}</div>}
                                            </th>
                                            {roles.map((role) => {
                                                const granted = role.permissions.includes(p.name);
                                                const byDefault = role.defaults.includes(p.name);
                                                // A system role's cell differs from its defaults by the right or, both granted, by its scope.
                                                const changed =
                                                    role.system &&
                                                    (granted !== byDefault ||
                                                        (granted &&
                                                            p.scoped &&
                                                            (role.scopes[p.name] ?? 'all') !== (role.default_scopes[p.name] ?? 'all')));
                                                const fixed = role.admin || p.locked;
                                                const key = `${role.id}:${p.name}`;

                                                return (
                                                    <td
                                                        key={role.id}
                                                        className={`px-2 py-2 text-center ${changed ? 'bg-amber-100 dark:bg-amber-900' : ''}`}
                                                        data-changed={changed ? 'true' : undefined}
                                                    >
                                                        <div className="flex flex-col items-center gap-1">
                                                            <div className="flex items-center justify-center gap-1">
                                                                <Checkbox
                                                                    checked={granted}
                                                                    disabled={fixed || saving === key}
                                                                    aria-label={`${p.label} - ${role.label}`}
                                                                    onCheckedChange={(c) => toggle(role, p.name, c === true)}
                                                                />
                                                                {fixed && <Lock className="text-muted-foreground h-3 w-3" aria-hidden />}
                                                            </div>
                                                            {p.scoped && (
                                                                <Select
                                                                    value={role.scopes[p.name] ?? 'all'}
                                                                    disabled={fixed || !granted || saving === key}
                                                                    onValueChange={(scope) => setScope(role, p.name, scope)}
                                                                >
                                                                    <SelectTrigger
                                                                        className="h-7 w-full text-xs"
                                                                        aria-label={`Périmètre de ${p.label} - ${role.label}`}
                                                                    >
                                                                        <SelectValue />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        {scopeOptions
                                                                            .filter((o) => p.scope_options.includes(o.value))
                                                                            .map((o) => (
                                                                                <SelectItem key={o.value} value={o.value}>
                                                                                    {o.label}
                                                                                </SelectItem>
                                                                            ))}
                                                                    </SelectContent>
                                                                </Select>
                                                            )}
                                                        </div>
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            ))}
                        </table>
                    </div>
                </TooltipProvider>
            </div>

            <NewRoleDialog open={creating} roles={roles} onClose={() => setCreating(false)} />
            <RenameDialog role={renaming} onClose={() => setRenaming(null)} />
        </AppLayout>
    );
}
