import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: string;
    apprentice: string;
    employer: string | null;
    training: string | null;
    pending: number;
}

interface Props {
    rows: Row[];
    total: number;
    q: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: "Livrets d'apprentissage", href: '/admissions/livret' }];

export default function BookletIndex({ rows, total, q }: Props) {
    const [search, setSearch] = useState(q);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Livrets d'apprentissage" />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Livrets d'apprentissage"
                    description="Les apprentis que vous suivez : pages du mois, grille de compétences et visites en entreprise."
                />
                <form
                    className="max-w-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(route('admissions.booklet.index'), search ? { q: search } : {}, { preserveState: true, replace: true });
                    }}
                >
                    <Input aria-label="Rechercher un apprenti" placeholder="Rechercher un apprenti…" value={search} onChange={(e) => setSearch(e.target.value)} />
                </form>

                {rows.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Aucun apprenti à suivre.</p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Apprenti</th>
                                    <th className="px-3 py-2 font-medium">Entreprise</th>
                                    <th className="px-3 py-2 font-medium">Formation</th>
                                    <th className="px-3 py-2 font-medium">Signatures en attente</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r) => (
                                    <tr key={r.id} className="border-t">
                                        <td className="px-3 py-2 font-medium">
                                            <Link href={route('admissions.booklet.show', r.id)} className="hover:underline">
                                                {r.apprentice}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2">{r.employer ?? '-'}</td>
                                        <td className="px-3 py-2">{r.training ?? '-'}</td>
                                        <td className="px-3 py-2">
                                            {r.pending > 0 ? <Badge variant="destructive">{r.pending}</Badge> : <span className="text-muted-foreground">Aucune</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {total > rows.length && <p className="text-muted-foreground text-sm">{total} apprentis au total : affinez la recherche.</p>}
            </div>
        </AppLayout>
    );
}
