import { BookletGrid } from '@/components/admissions/booklet-grid';
import { BookletMonths } from '@/components/admissions/booklet-months';
import { type BookletMonthRow, type Grid, type Visit } from '@/components/admissions/booklet-types';
import { BookletVisits } from '@/components/admissions/booklet-visits';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { FileDown } from 'lucide-react';

type Tab = 'suivi' | 'grille' | 'visites';

interface Props {
    candidature: {
        id: string;
        number: string;
        apprentice: string;
        apprentice_email: string | null;
        employer: string | null;
        tutor: string | null;
        tutor_email: string | null;
        training: string | null;
        contract: { starts_on: string; ends_on: string };
    };
    months: BookletMonthRow[];
    grid: Grid;
    visits: Visit[];
    tab: Tab;
}

const TABS: { key: Tab; label: string }[] = [
    { key: 'suivi', label: 'Suivi mensuel' },
    { key: 'grille', label: 'Grille de compétences' },
    { key: 'visites', label: 'Visites en entreprise' },
];

export default function BookletShow({ candidature, months, grid, visits, tab }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: "Livrets d'apprentissage", href: '/admissions/livret' },
        { title: candidature.apprentice, href: `/admissions/livret/${candidature.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Livret de ${candidature.apprentice}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title={`Livret de ${candidature.apprentice}`}
                        description={[
                            candidature.training,
                            candidature.employer,
                            candidature.tutor ? `tuteur : ${candidature.tutor}` : 'tuteur à indiquer dans le contrat',
                            `du ${candidature.contract.starts_on} au ${candidature.contract.ends_on}`,
                        ]
                            .filter(Boolean)
                            .join(' - ')}
                    />
                    <Button variant="outline" asChild>
                        <a href={route('admissions.booklet.pdf', candidature.id)} target="_blank" rel="noreferrer">
                            <FileDown className="mr-1 h-4 w-4" /> Livret (PDF)
                        </a>
                    </Button>
                </div>

                <nav className="flex flex-wrap gap-1 border-b" aria-label="Onglets du livret">
                    {TABS.map((t) => (
                        <Link
                            key={t.key}
                            href={route('admissions.booklet.show', candidature.id) + `?onglet=${t.key}`}
                            preserveScroll
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm',
                                tab === t.key ? 'border-primary font-medium' : 'text-muted-foreground border-transparent hover:text-foreground',
                            )}
                            aria-current={tab === t.key ? 'page' : undefined}
                        >
                            {t.label}
                        </Link>
                    ))}
                </nav>

                {tab === 'suivi' && <BookletMonths candidatureId={candidature.id} months={months} />}
                {tab === 'grille' && <BookletGrid candidatureId={candidature.id} grid={grid} />}
                {tab === 'visites' && <BookletVisits candidatureId={candidature.id} visits={visits} />}
            </div>
        </AppLayout>
    );
}
