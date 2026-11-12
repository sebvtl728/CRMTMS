import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface AuditRow {
    id: string;
    created_at: string;
    event: string;
    user: string | null;
    auditable_type: string | null;
    auditable_id: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip: string | null;
}

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Filters {
    user: string;
    event: string;
    type: string;
    from: string;
    to: string;
}

interface AuditIndexProps {
    logs: { data: AuditRow[]; links: PageLink[]; total: number; from: number | null; to: number | null };
    filters: Filters;
    users: { id: string; name: string }[];
    events: string[];
    types: string[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: "Journal d'audit", href: '/admin/audit' }];

const EVENT_LABELS: Record<string, string> = {
    created: 'Création',
    updated: 'Modification',
    deleted: 'Suppression',
    restored: 'Restauration',
    'auth.login': 'Connexion',
    'auth.failed': 'Échec de connexion',
    'auth.logout': 'Déconnexion',
    'auth.2fa_enabled': 'Double authentification activée',
    'auth.2fa_disabled': 'Double authentification désactivée',
    'auth.2fa_reset_by_operator': 'Double authentification réinitialisée (exploitant)',
    'access.role_changed': 'Changement de rôle',
    'export.run': 'Export',
    'users.invitation_sent': 'Invitation envoyée',
    'users.invitation_send_failed': "Échec d'envoi de l'invitation",
    'users.activation_reissued': "Lien d'activation réémis",
    'settings.security_changed': 'Paramètres de sécurité modifiés',
};

const dateTime = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'medium', timeZone: 'Europe/Paris' });

const eventLabel = (event: string) => EVENT_LABELS[event] ?? event;
const TYPE_LABELS: Record<string, string> = {
    tenant: 'Organisme',
    user: 'Utilisateur',
    invitation: 'Invitation',
    company: 'Société',
    reference_item: 'Référentiel',
    connector_config: 'Connecteur',
    organisation: 'Entreprise',
    person: 'Personne',
    affiliation: 'Rattachement',
    note: 'Note',
    attachment: 'Pièce jointe',
};
// Older journal rows hold the class name: show its last segment.
const typeLabel = (type: string | null) => (type ? (TYPE_LABELS[type] ?? type.split('\\').pop() ?? type) : '—');

function Values({ old, current }: { old: Record<string, unknown> | null; current: Record<string, unknown> | null }) {
    const keys = Array.from(new Set([...Object.keys(old ?? {}), ...Object.keys(current ?? {})]));

    if (keys.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <ul className="space-y-0.5">
            {keys.map((key) => (
                <li key={key} className="break-all">
                    <span className="font-medium">{key}</span> :{' '}
                    {old && key in old ? <span className="text-muted-foreground line-through">{String(old[key])}</span> : null}
                    {old && key in old && current && key in current ? ' → ' : ''}
                    {current && key in current ? <span>{String(current[key])}</span> : null}
                </li>
            ))}
        </ul>
    );
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm shadow-xs';

export default function AuditIndex({ logs, filters, users, events, types }: AuditIndexProps) {
    const [values, setValues] = useState<Filters>(filters);

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('admin.audit.index'), { ...values }, { preserveState: true, replace: true });
    };

    const reset = () => {
        const empty = { user: '', event: '', type: '', from: '', to: '' };
        setValues(empty);
        router.get(route('admin.audit.index'), {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Journal d'audit" />

            <div className="flex flex-col gap-6 p-4">
                <Heading title="Journal d'audit" description="Historique en lecture seule des modifications et des évènements d'authentification" />

                <form onSubmit={apply} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                    <div className="grid gap-1">
                        <Label htmlFor="user">Utilisateur</Label>
                        <select
                            id="user"
                            className={selectClass}
                            value={values.user}
                            onChange={(e) => setValues({ ...values, user: e.target.value })}
                        >
                            <option value="">Tous</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="event">Évènement</Label>
                        <select
                            id="event"
                            className={selectClass}
                            value={values.event}
                            onChange={(e) => setValues({ ...values, event: e.target.value })}
                        >
                            <option value="">Tous</option>
                            {events.map((event) => (
                                <option key={event} value={event}>
                                    {eventLabel(event)}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="type">Type d'objet</Label>
                        <select
                            id="type"
                            className={selectClass}
                            value={values.type}
                            onChange={(e) => setValues({ ...values, type: e.target.value })}
                        >
                            <option value="">Tous</option>
                            {types.map((type) => (
                                <option key={type} value={type}>
                                    {typeLabel(type)}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="from">Du</Label>
                        <Input id="from" type="date" value={values.from} onChange={(e) => setValues({ ...values, from: e.target.value })} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="to">Au</Label>
                        <Input id="to" type="date" value={values.to} onChange={(e) => setValues({ ...values, to: e.target.value })} />
                    </div>
                    <div className="flex items-end gap-2">
                        <Button type="submit">Filtrer</Button>
                        <Button type="button" variant="outline" onClick={reset}>
                            Réinitialiser
                        </Button>
                    </div>
                </form>

                <section className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-2 font-medium">Date</th>
                                <th className="px-4 py-2 font-medium">Utilisateur</th>
                                <th className="px-4 py-2 font-medium">Évènement</th>
                                <th className="px-4 py-2 font-medium">Objet</th>
                                <th className="px-4 py-2 font-medium">Détail</th>
                                <th className="px-4 py-2 font-medium">Adresse IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-6 text-center">
                                        Aucun évènement.
                                    </td>
                                </tr>
                            )}
                            {logs.data.map((log) => (
                                <tr key={log.id} className="border-t align-top">
                                    <td className="px-4 py-2 whitespace-nowrap">{dateTime.format(new Date(log.created_at))}</td>
                                    <td className="px-4 py-2">{log.user ?? '—'}</td>
                                    <td className="px-4 py-2">{eventLabel(log.event)}</td>
                                    <td className="px-4 py-2">
                                        {typeLabel(log.auditable_type)}
                                        {log.auditable_id && <div className="text-muted-foreground text-xs break-all">{log.auditable_id}</div>}
                                    </td>
                                    <td className="px-4 py-2">
                                        <Values old={log.old_values} current={log.new_values} />
                                    </td>
                                    <td className="px-4 py-2">{log.ip ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <nav className="flex flex-wrap items-center justify-between gap-2 text-sm" aria-label="Pagination">
                    <span className="text-muted-foreground">{logs.total === 0 ? '0 résultat' : `${logs.from} à ${logs.to} sur ${logs.total}`}</span>
                    <div className="flex flex-wrap gap-1">
                        {logs.links.map((link, index) =>
                            link.url ? (
                                <Button key={index} asChild size="sm" variant={link.active ? 'default' : 'outline'}>
                                    <Link href={link.url} preserveScroll dangerouslySetInnerHTML={{ __html: pageLabel(link.label) }} />
                                </Button>
                            ) : null,
                        )}
                    </div>
                </nav>
            </div>
        </AppLayout>
    );
}

function pageLabel(label: string): string {
    if (label.includes('Previous')) return '&laquo; Précédent';
    if (label.includes('Next')) return 'Suivant &raquo;';
    return label;
}
