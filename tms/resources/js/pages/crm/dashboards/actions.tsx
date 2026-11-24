import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, ChevronRight } from 'lucide-react';

interface Fiche {
    id: string;
    name: string;
}

interface DoneRow {
    id: string;
    type: string | null;
    subject: string;
    done_at: string | null;
    result: string | null;
    responsible: string | null;
    organisation: Fiche | null;
    person: Fiche | null;
    opportunity: Fiche | null;
    /** The viewer may open the fiche the action hangs on. */
    fiche_visible: boolean;
}

interface Paginated<T> {
    data: T[];
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface DoneActionsProps {
    title: string;
    period: string;
    back: string;
    actions: Paginated<DoneRow>;
}

/** The page of the fiche an action hangs on: opportunity first, then person, then entreprise. */
function ficheUrl(row: DoneRow): string | null {
    if (row.opportunity) return route('crm.opportunities.show', row.opportunity.id);
    if (row.person) return route('crm.people.show', row.person.id);
    if (row.organisation) return route('crm.organisations.show', row.organisation.id);

    return null;
}

function ficheName(row: DoneRow): string {
    return row.opportunity?.name ?? row.person?.name ?? row.organisation?.name ?? '—';
}

export default function DoneActions({ title, period, back, actions }: DoneActionsProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Tableaux de bord', href: back },
        { title, href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title={title} description={`Période ${period} · ${actions.total} action${actions.total > 1 ? 's' : ''}`} />
                    <Button asChild variant="outline">
                        <Link href={back}>
                            <ArrowLeft className="h-4 w-4" />
                            Tableaux de bord
                        </Link>
                    </Button>
                </div>

                {actions.total === 0 ? (
                    <p className="text-muted-foreground text-sm">Aucune action faite dans la période.</p>
                ) : (
                    <>
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Faite le</th>
                                        <th className="px-3 py-2 font-medium">Type</th>
                                        <th className="px-3 py-2 font-medium">Objet</th>
                                        <th className="px-3 py-2 font-medium">Fiche</th>
                                        <th className="px-3 py-2 font-medium">Résultat</th>
                                        <th className="px-3 py-2 font-medium">Responsable</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {actions.data.map((row) => {
                                        const url = row.fiche_visible ? ficheUrl(row) : null;

                                        return (
                                            <tr key={row.id} className="border-t">
                                                <td className="px-3 py-2 whitespace-nowrap">{row.done_at ? formatDateTime(row.done_at) : '—'}</td>
                                                <td className="px-3 py-2">{row.type ?? '—'}</td>
                                                <td className="px-3 py-2">{row.subject}</td>
                                                <td className="px-3 py-2">
                                                    {url ? (
                                                        <Link href={url} className="underline underline-offset-4">
                                                            {ficheName(row)}
                                                        </Link>
                                                    ) : (
                                                        ficheName(row)
                                                    )}
                                                </td>
                                                <td className="px-3 py-2">{row.result ?? '—'}</td>
                                                <td className="px-3 py-2">{row.responsible ?? '—'}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        <nav aria-label="Pagination" className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {actions.from}–{actions.to} sur {actions.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!actions.prev_page_url}
                                    onClick={() => actions.prev_page_url && router.get(actions.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!actions.next_page_url}
                                    onClick={() => actions.next_page_url && router.get(actions.next_page_url)}
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
