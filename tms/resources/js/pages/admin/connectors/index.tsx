import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Pencil, RotateCcw } from 'lucide-react';

interface ConnectorRow {
    key: string;
    label: string;
    kind: string;
    configured: boolean;
    enabled: boolean;
}

interface RunRow {
    id: string;
    connector: string;
    direction: 'in' | 'out';
    status: 'pending' | 'success' | 'failed';
    summary: string | null;
    error: string | null;
    started_at: string;
    finished_at: string | null;
    retryable: boolean;
}

interface IndexProps {
    connectors: ConnectorRow[];
    runs: RunRow[];
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Connecteurs', href: '/admin/connectors' }];

const statusLabel = { pending: 'En cours', success: 'Réussie', failed: 'Échec' } as const;
const statusVariant = { pending: 'secondary', success: 'default', failed: 'destructive' } as const;

const formatDate = (iso: string) => new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });

export default function ConnectorsIndex({ connectors, runs, status }: IndexProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Connecteurs" />

            <div className="flex flex-col gap-6 p-4">
                <Heading title="Connecteurs" description="Liens avec vos logiciels externes (messagerie, comptabilité, signature…)" />

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <section className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-2 font-medium">Connecteur</th>
                                <th className="px-4 py-2 font-medium">Type</th>
                                <th className="px-4 py-2 font-medium">État</th>
                                <th className="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {connectors.map((connector) => (
                                <tr key={connector.key} className="border-t">
                                    <td className="px-4 py-2 font-medium">{connector.label}</td>
                                    <td className="px-4 py-2">{connector.kind}</td>
                                    <td className="px-4 py-2">
                                        {!connector.configured ? (
                                            <Badge variant="outline">Non configuré</Badge>
                                        ) : connector.enabled ? (
                                            <Badge>Actif</Badge>
                                        ) : (
                                            <Badge variant="secondary">Désactivé</Badge>
                                        )}
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        <Button asChild variant="ghost" size="sm">
                                            <Link href={route('admin.connectors.edit', connector.key)}>
                                                <Pencil className="h-4 w-4" />
                                                Configurer
                                            </Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <section className="space-y-2">
                    <h2 className="text-base font-medium">Dernières exécutions</h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-4 py-2 font-medium">Date</th>
                                    <th className="px-4 py-2 font-medium">Connecteur</th>
                                    <th className="px-4 py-2 font-medium">Sens</th>
                                    <th className="px-4 py-2 font-medium">Résultat</th>
                                    <th className="px-4 py-2 font-medium">Détail</th>
                                    <th className="px-4 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {runs.map((run) => (
                                    <tr key={run.id} className="border-t align-top">
                                        <td className="px-4 py-2 whitespace-nowrap">{formatDate(run.started_at)}</td>
                                        <td className="px-4 py-2">{run.connector}</td>
                                        <td className="px-4 py-2">{run.direction === 'in' ? 'Entrant' : 'Sortant'}</td>
                                        <td className="px-4 py-2">
                                            <Badge variant={statusVariant[run.status]}>{statusLabel[run.status]}</Badge>
                                        </td>
                                        <td className="text-muted-foreground px-4 py-2">{run.error ?? run.summary ?? '-'}</td>
                                        <td className="px-4 py-2 text-right">
                                            {run.retryable && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => router.post(route('admin.connectors.runs.retry', run.id))}
                                                >
                                                    <RotateCcw className="h-4 w-4" />
                                                    Relancer
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {runs.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground px-4 py-6 text-center">
                                            Aucune exécution.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
