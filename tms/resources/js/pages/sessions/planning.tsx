import { FilterSelect } from '@/components/crm/filter-select';
import Heading from '@/components/heading';
import { PlanningBoard, type PlanningPeriodData, type PlanningSeance } from '@/components/sessions/planning-board';
import { SeanceDetails } from '@/components/sessions/seance-details';
import { AttendanceDialog } from '@/components/sessions/session-attendance';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { type ClosureRow } from '@/lib/sessions';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { useState } from 'react';

type Filters = { trainer: string | null; room: string | null; session: string | null; das: string | null };

interface Props {
    period: PlanningPeriodData;
    holidays: Record<string, string>;
    closures: ClosureRow[];
    seances: PlanningSeance[];
    filters: Filters;
    options: { trainers: Option[]; rooms: Option[]; sessions: Option[]; das: Option[] };
    can: { my_planning: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sessions', href: '/sessions' },
    { title: 'Planning des séances', href: '/sessions/planning' },
];

export default function SessionsPlanning({ period, holidays, closures, seances, filters, options, can }: Props) {
    const [open, setOpen] = useState<PlanningSeance | null>(null);
    const [attendance, setAttendance] = useState<string | null>(null);

    const visit = (changes: Partial<Filters & { view: string; date: string }>) => {
        const all: Record<string, string | null> = { view: period.view, date: period.date, ...filters, ...changes };
        // A new DAS resets the trainer (the list of trainers follows the DAS).
        if ('das' in changes) all.trainer = null;
        const query = Object.fromEntries(Object.entries(all).filter(([, v]) => v)) as Record<string, string>;
        router.get(route('sessions.planning'), query, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Planning · ${period.label}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Planning des séances" description="Toutes les séances de votre périmètre, par mois ou par semaine." />
                    {can.my_planning && (
                        <Button asChild variant="outline">
                            <Link href={route('my-planning.show')}>
                                <CalendarClock className="h-4 w-4" />
                                Mon planning
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <FilterSelect label="DAS" value={filters.das} options={options.das} onChange={(v) => visit({ das: v })} />
                    <FilterSelect label="Formateur" value={filters.trainer} options={options.trainers} onChange={(v) => visit({ trainer: v })} />
                    <FilterSelect label="Salle" value={filters.room} options={options.rooms} onChange={(v) => visit({ room: v })} />
                    <FilterSelect label="Session" value={filters.session} options={options.sessions} onChange={(v) => visit({ session: v })} />
                </div>

                <PlanningBoard
                    period={period}
                    seances={seances}
                    holidays={holidays}
                    closures={closures}
                    showTrainer
                    onNavigate={(view, date) => visit({ view, date })}
                    onOpen={setOpen}
                />
                {seances.length === 0 && <p className="text-muted-foreground text-sm">Aucune séance sur cette période avec ces filtres.</p>}
            </div>

            <SeanceDetails
                seance={open}
                onClose={() => setOpen(null)}
                onAttendance={(s) => {
                    setOpen(null);
                    setAttendance(s.id);
                }}
            />
            <AttendanceDialog seanceId={attendance} open={attendance !== null} onClose={() => setAttendance(null)} />
        </AppLayout>
    );
}
