import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatSize, type Paginated } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Download, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface BrochureRow {
    id: string;
    title: string;
    original_name: string | null;
    size: number | null;
    versions_count: number;
    updated_at: string | null;
    trainings: string[];
    das: string[];
}

interface IndexProps {
    brochures: Paginated<BrochureRow>;
    filters: { q: string };
    can: { manage: boolean; delete: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Catalogue', href: '/catalogue' },
    { title: 'Plaquettes', href: '/catalogue/brochures' },
];

function concerns(row: BrochureRow): string {
    const parts = [...row.das.map((d) => `DAS ${d}`), ...row.trainings];

    return parts.length > 0 ? parts.join(', ') : '—';
}

export default function BrochuresIndex({ brochures, filters, can, status }: IndexProps) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [search, setSearch] = useState(filters.q);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(
            () => router.get(route('catalogue.brochures.index'), search ? { q: search } : {}, { preserveState: true, replace: true }),
            300,
        );
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const destroy = (row: BrochureRow) => {
        if (window.confirm(`Mettre la plaquette « ${row.title} » à la corbeille ? Les exemplaires déjà envoyés restent consultables.`)) {
            router.delete(route('catalogue.brochures.destroy', row.id), { preserveScroll: true });
        }
    };

    const actions = (row: BrochureRow) => (
        <div className="flex flex-wrap gap-1">
            <Button asChild variant="outline" size="sm">
                <a href={route('catalogue.brochures.download', row.id)}>
                    <Download className="h-4 w-4" />
                    Télécharger
                </a>
            </Button>
            {can.manage && (
                <Button asChild variant="outline" size="sm">
                    <Link href={route('catalogue.brochures.edit', row.id)}>Modifier</Link>
                </Button>
            )}
            {can.delete && (
                <Button type="button" variant="ghost" size="sm" className="text-destructive" onClick={() => destroy(row)}>
                    Corbeille
                </Button>
            )}
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Plaquettes" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Plaquettes" description="Plaquettes PDF des formations, à joindre aux devis ou à envoyer à un contact" />
                    <div className="flex flex-wrap items-start gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('catalogue.index')}>Formations</Link>
                        </Button>
                        {can.manage && (
                            <Button asChild>
                                <Link href={route('catalogue.brochures.create')}>
                                    <Plus className="h-4 w-4" />
                                    Nouvelle plaquette
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}
                <InputError message={errors.brochure} />

                <div className="grid max-w-md gap-1">
                    <Label htmlFor="q" className="text-xs">
                        Recherche (titre)
                    </Label>
                    <Input id="q" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ex. HACCP" />
                </div>

                <p className="text-sm">
                    {brochures.total} plaquette{brochures.total > 1 ? 's' : ''}
                </p>

                {brochures.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {filters.q ? 'Aucune plaquette ne correspond à cette recherche.' : 'Aucune plaquette pour le moment.'}
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto rounded-xl border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Titre</th>
                                        <th className="px-3 py-2 font-medium">Concerne</th>
                                        <th className="px-3 py-2 font-medium">Fichier</th>
                                        <th className="px-3 py-2 font-medium">Mise à jour</th>
                                        <th className="px-3 py-2 font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {brochures.data.map((b) => (
                                        <tr key={b.id} className="hover:bg-muted/30 border-t align-top">
                                            <td className="px-3 py-2 font-medium">{b.title}</td>
                                            <td className="px-3 py-2">{concerns(b)}</td>
                                            <td className="px-3 py-2">
                                                {b.original_name ?? '—'} <span className="text-muted-foreground">{formatSize(b.size)}</span>
                                                {b.versions_count > 1 && <span className="text-muted-foreground"> · {b.versions_count} versions</span>}
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">{formatDate(b.updated_at)}</td>
                                            <td className="px-3 py-2">{actions(b)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="grid gap-2 md:hidden">
                            {brochures.data.map((b) => (
                                <li key={b.id} className="grid gap-1 rounded-xl border p-3 text-sm">
                                    <div className="font-medium">{b.title}</div>
                                    <div className="text-muted-foreground text-xs">{concerns(b)}</div>
                                    <div className="text-xs">
                                        {b.original_name} {formatSize(b.size)}
                                    </div>
                                    {actions(b)}
                                </li>
                            ))}
                        </ul>

                        <nav aria-label="Pagination" className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {brochures.from}–{brochures.to} sur {brochures.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!brochures.prev_page_url}
                                    onClick={() => brochures.prev_page_url && router.get(brochures.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!brochures.next_page_url}
                                    onClick={() => brochures.next_page_url && router.get(brochures.next_page_url)}
                                >
                                    Suivant
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </nav>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
