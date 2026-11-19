import { AbandonButton, StatusBadge, UndoDialog, type BatchStatus } from '@/components/crm/import-wizard';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, type Paginated } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Upload } from 'lucide-react';
import { useState } from 'react';

interface Row {
    id: string;
    created_at: string;
    file: string;
    target: string;
    author: string | null;
    status: BatchStatus;
    stuck: boolean;
    total: number;
    created: number;
    updated: number;
    skipped: number;
    errors: number;
    canUndo: boolean;
    canAbandon: boolean;
}

interface IndexProps {
    batches: Paginated<Row>;
    seesAll: boolean;
    flash?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Imports', href: '/crm/import' }];

const number = new Intl.NumberFormat('fr-FR');

function Counts({ row }: { row: Row }) {
    return (
        <span className="text-muted-foreground text-xs">
            {number.format(row.created)} créées · {number.format(row.updated)} mises à jour · {number.format(row.skipped)} ignorées ·{' '}
            <span className={row.errors > 0 ? 'font-medium text-red-600 dark:text-red-400' : ''}>{number.format(row.errors)} erreurs</span>
        </span>
    );
}

export default function ImportIndex({ batches, seesAll, flash }: IndexProps) {
    const [undoing, setUndoing] = useState<string | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Imports" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Imports"
                        description={seesAll ? 'Tous les imports de fichiers de votre organisme' : 'Vos imports de fichiers (Excel ou CSV)'}
                    />
                    <Button asChild className="hidden md:inline-flex">
                        <Link href={route('crm.import.create')}>
                            <Upload className="h-4 w-4" />
                            Nouvel import
                        </Link>
                    </Button>
                </div>

                {flash && <div className="text-sm font-medium text-green-600">{flash}</div>}

                {batches.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        Aucun import pour le moment.
                        <p className="mt-1">
                            L&apos;import se fait depuis un ordinateur : déposez un fichier Excel ou CSV pour créer vos entreprises et personnes en
                            une fois.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto rounded-xl border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">Date</th>
                                        <th className="px-4 py-2 font-medium">Fichier</th>
                                        <th className="px-4 py-2 font-medium">Contenu</th>
                                        {seesAll && <th className="px-4 py-2 font-medium">Auteur</th>}
                                        <th className="px-4 py-2 font-medium">Statut</th>
                                        <th className="px-4 py-2 text-right font-medium">Créées</th>
                                        <th className="px-4 py-2 text-right font-medium">Mises à jour</th>
                                        <th className="px-4 py-2 text-right font-medium">Ignorées</th>
                                        <th className="px-4 py-2 text-right font-medium">Erreurs</th>
                                        <th className="px-4 py-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {batches.data.map((b) => (
                                        <tr key={b.id}>
                                            <td className="px-4 py-2 whitespace-nowrap">{formatDateTime(b.created_at)}</td>
                                            <td className="px-4 py-2">
                                                <Link href={route('crm.import.show', b.id)} className="font-medium hover:underline">
                                                    {b.file}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-2">{b.target}</td>
                                            {seesAll && <td className="px-4 py-2">{b.author ?? '-'}</td>}
                                            <td className="px-4 py-2">
                                                <StatusBadge status={b.status} stuck={b.stuck} />
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">{number.format(b.created)}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{number.format(b.updated)}</td>
                                            <td className="px-4 py-2 text-right tabular-nums">{number.format(b.skipped)}</td>
                                            <td
                                                className={
                                                    'px-4 py-2 text-right tabular-nums ' + (b.errors > 0 ? 'text-red-600 dark:text-red-400' : '')
                                                }
                                            >
                                                {number.format(b.errors)}
                                            </td>
                                            <td className="px-4 py-2 text-right whitespace-nowrap">
                                                {b.canUndo && (
                                                    <Button variant="outline" size="sm" onClick={() => setUndoing(b.id)}>
                                                        Annuler l&apos;import
                                                    </Button>
                                                )}
                                                {b.canAbandon && <AbandonButton batchId={b.id} size="sm" />}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="grid gap-3 md:hidden">
                            {batches.data.map((b) => (
                                <li key={b.id}>
                                    <Link href={route('crm.import.show', b.id)} className="hover:bg-muted/30 block rounded-xl border p-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="font-medium break-all">{b.file}</span>
                                            <StatusBadge status={b.status} stuck={b.stuck} />
                                        </div>
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            {b.target} · {formatDateTime(b.created_at)}
                                            {seesAll && b.author ? ` · ${b.author}` : ''}
                                        </p>
                                        <p className="mt-1">
                                            <Counts row={b} />
                                        </p>
                                    </Link>
                                </li>
                            ))}
                        </ul>

                        <nav aria-label="Pagination" className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {batches.from}–{batches.to} sur {batches.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!batches.prev_page_url}
                                    onClick={() => batches.prev_page_url && router.get(batches.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!batches.next_page_url}
                                    onClick={() => batches.next_page_url && router.get(batches.next_page_url)}
                                >
                                    Suivant
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </nav>
                    </>
                )}
            </div>

            {undoing && <UndoDialog batchId={undoing} open onOpenChange={(open) => !open && setUndoing(null)} />}
        </AppLayout>
    );
}
