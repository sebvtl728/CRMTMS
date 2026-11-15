import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Lock, RotateCcw } from 'lucide-react';
import { useState } from 'react';

interface RoleColumn {
    id: string;
    /** Name of the role (system value, or custom_… never shown). */
    value: string;
    label: string;
    locked: boolean;
    granted: string[];
    defaults: string[];
}

interface PermissionRow {
    name: string;
    label: string;
    description: string;
    /** Administration-level power, worth a second thought before granting it. */
    warning: string | null;
}

interface RolesProps {
    roles: RoleColumn[];
    groups: { label: string; permissions: PermissionRow[] }[];
    /** Rights only the administrator holds, never editable here. */
    locked: string[];
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Rôles et droits', href: '/admin/roles' }];

export default function Roles({ roles, groups, locked, status }: RolesProps) {
    const [saving, setSaving] = useState<string | null>(null);

    const toggle = (role: RoleColumn, permission: string, granted: boolean) => {
        const key = `${role.id}:${permission}`;
        setSaving(key);
        router.post(
            route('admin.roles.update', role.id),
            { permission, granted },
            { preserveScroll: true, preserveState: true, onFinish: () => setSaving(null) },
        );
    };

    const reset = (role: RoleColumn) => {
        if (!window.confirm(`Rétablir les droits par défaut du rôle ${role.label} ? Les droits ajoutés ou retirés pour ce rôle seront annulés.`))
            return;
        router.post(route('admin.roles.reset', role.id), {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Rôles et droits" />

            <div className="flex flex-col gap-4 p-4">
                <Heading
                    title="Rôles et droits"
                    description="Ce que chaque rôle peut faire dans votre organisme. Chaque case est enregistrée dès que vous la cochez ou la décochez."
                />

                {status && (
                    <div role="status" className="text-sm font-medium text-green-600">
                        {status}
                    </div>
                )}

                <ul className="text-muted-foreground list-disc space-y-1 pl-5 text-sm">
                    <li>Le rôle Administrateur de l'organisme a toujours tous les droits : il ne se modifie pas.</li>
                    <li>
                        « Gérer les utilisateurs », « Gérer les rôles et droits » et « Gérer les connecteurs » restent réservés à l'administrateur.
                    </li>
                    <li>
                        Une case <span className="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-100">surlignée</span>{' '}
                        diffère des droits par défaut du rôle.
                    </li>
                    <li>La double authentification reste obligatoire pour l'administrateur et la direction, quels que soient leurs droits.</li>
                </ul>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[900px] text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th scope="col" className="px-3 py-2 text-left font-medium">
                                    Droit
                                </th>
                                {roles.map((role) => (
                                    <th key={role.id} scope="col" className="px-2 py-2 text-center align-bottom font-medium">
                                        <div>{role.label}</div>
                                        {role.value === 'responsable_das' && (
                                            <div className="text-muted-foreground mt-1 text-xs font-normal">
                                                Ne voit que les fiches de ses DAS (règle de données).
                                            </div>
                                        )}
                                        {!role.locked && (
                                            <Button variant="ghost" size="sm" className="mt-1 h-7 text-xs" onClick={() => reset(role)}>
                                                <RotateCcw className="h-3 w-3" />
                                                Rétablir les droits par défaut
                                            </Button>
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
                                        className="bg-muted/30 px-3 py-2 text-left text-xs font-semibold uppercase"
                                    >
                                        {group.label}
                                    </th>
                                </tr>
                                {group.permissions.map((p) => (
                                    <tr key={p.name} className="border-t">
                                        <th scope="row" className="px-3 py-2 text-left font-normal">
                                            <div className="font-medium">{p.label}</div>
                                            <div className="text-muted-foreground text-xs">{p.description}</div>
                                            {p.warning && <div className="mt-1 text-xs text-amber-700 dark:text-amber-300">⚠ {p.warning}</div>}
                                        </th>
                                        {roles.map((role) => {
                                            const granted = role.granted.includes(p.name);
                                            const changed = granted !== role.defaults.includes(p.name);
                                            const fixed = role.locked || locked.includes(p.name);
                                            const key = `${role.id}:${p.name}`;

                                            return (
                                                <td
                                                    key={role.id}
                                                    className={`px-2 py-2 text-center ${changed ? 'bg-amber-100 dark:bg-amber-900' : ''}`}
                                                    data-changed={changed ? 'true' : undefined}
                                                >
                                                    <div className="flex items-center justify-center gap-1">
                                                        <Checkbox
                                                            checked={granted}
                                                            disabled={fixed || saving === key}
                                                            aria-label={`${p.label} — ${role.label}`}
                                                            onCheckedChange={(c) => toggle(role, p.name, c === true)}
                                                        />
                                                        {fixed && <Lock className="text-muted-foreground h-3 w-3" aria-hidden />}
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
            </div>
        </AppLayout>
    );
}
