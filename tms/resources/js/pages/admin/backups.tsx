import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Last {
    finished_at: string;
    success: boolean;
    error: string | null;
    version: string | null;
    size: string | null;
    snapshot: string | null;
    remote: { configured: boolean; success: boolean | null };
}

interface Snapshot {
    id: string;
    time: string;
    tags: string[];
}

interface BackupsProps {
    last: Last | null;
    stale: boolean;
    snapshots: Snapshot[];
    running: boolean;
    pending: boolean;
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sauvegardes', href: '/admin/backups' }];

const dateTime = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeStyle: 'short', timeZone: 'Europe/Paris' });

function remoteLabel(remote: Last['remote']): string {
    if (!remote.configured) return 'Non configurée';
    if (remote.success === null) return 'Configurée, résultat inconnu';
    return remote.success ? 'Configurée, dernière copie réussie' : 'Configurée, dernière copie échouée';
}

export default function Backups({ last, stale, snapshots, running, pending, status }: BackupsProps) {
    const { post, processing, errors } = useForm<{ request?: string }>({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.backups.request'), { preserveScroll: true });
    };

    const label = running ? 'Sauvegarde en cours…' : pending ? 'Sauvegarde demandée…' : 'Sauvegarder maintenant';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sauvegardes" />

            <div className="flex flex-col gap-6 p-4">
                <Heading title="Sauvegardes" description="État des sauvegardes de votre serveur" />

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                {last === null ? (
                    <Alert variant="destructive">
                        <AlertDescription>Aucune sauvegarde connue. Vérifiez que les sauvegardes automatiques fonctionnent.</AlertDescription>
                    </Alert>
                ) : (
                    <>
                        {!last.success && (
                            <Alert variant="destructive">
                                <AlertDescription>La dernière sauvegarde a échoué{last.error ? ` : ${last.error}` : '.'}</AlertDescription>
                            </Alert>
                        )}
                        {last.success && stale && (
                            <Alert variant="destructive">
                                <AlertDescription>La dernière sauvegarde réussie date de plus de 36 heures.</AlertDescription>
                            </Alert>
                        )}
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Dernière sauvegarde</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="grid max-w-xl grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                                    <dt className="text-muted-foreground">Date</dt>
                                    <dd>{dateTime.format(new Date(last.finished_at))}</dd>
                                    <dt className="text-muted-foreground">Résultat</dt>
                                    <dd>{last.success ? 'Réussie' : 'Échouée'}</dd>
                                    {last.size && (
                                        <>
                                            <dt className="text-muted-foreground">Taille</dt>
                                            <dd>{last.size}</dd>
                                        </>
                                    )}
                                    {last.version && (
                                        <>
                                            <dt className="text-muted-foreground">Version</dt>
                                            <dd>{last.version}</dd>
                                        </>
                                    )}
                                    {last.snapshot && (
                                        <>
                                            <dt className="text-muted-foreground">Identifiant</dt>
                                            <dd className="font-mono">{last.snapshot}</dd>
                                        </>
                                    )}
                                    <dt className="text-muted-foreground">Copie hors site</dt>
                                    <dd>{remoteLabel(last.remote)}</dd>
                                </dl>
                            </CardContent>
                        </Card>
                    </>
                )}

                <form onSubmit={submit} className="flex flex-col items-start gap-2">
                    <Button type="submit" disabled={running || pending || processing}>
                        {label}
                    </Button>
                    <InputError message={errors.request} />
                </form>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Sauvegardes disponibles</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {snapshots.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Aucune sauvegarde dans la liste.</p>
                        ) : (
                            <ul className="divide-y">
                                {snapshots.map((snapshot) => (
                                    <li key={snapshot.id} className="flex flex-wrap items-center gap-3 py-2 text-sm">
                                        <span>{dateTime.format(new Date(snapshot.time))}</span>
                                        <span className="text-muted-foreground font-mono">{snapshot.id}</span>
                                        {snapshot.tags.map((tag) => (
                                            <Badge key={tag} variant="secondary">
                                                {tag}
                                            </Badge>
                                        ))}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <p className="text-muted-foreground max-w-xl text-sm">
                    Conservez le mot de passe des sauvegardes et la clé de l&apos;application hors du serveur : sans eux, une sauvegarde ne peut pas
                    être restaurée. Voir le guide d&apos;installation (section « Sauvegardes »).
                </p>
            </div>
        </AppLayout>
    );
}
