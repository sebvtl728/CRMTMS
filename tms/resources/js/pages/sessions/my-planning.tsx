import Heading from '@/components/heading';
import { PlanningBoard, type PlanningPeriodData, type PlanningSeance } from '@/components/sessions/planning-board';
import { SeanceDetails } from '@/components/sessions/seance-details';
import { AttendanceDialog } from '@/components/sessions/session-attendance';
import { Unavailabilities, type UnavailabilityRow } from '@/components/sessions/unavailabilities';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type ClosureRow } from '@/lib/sessions';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarPlus } from 'lucide-react';
import { useState } from 'react';

interface Props {
    trainer: { id: string; label: string; status_label: string } | null;
    period: PlanningPeriodData;
    holidays: Record<string, string>;
    closures: ClosureRow[];
    seances: PlanningSeance[];
    unavailabilities: UnavailabilityRow[];
    /** « Mon agenda » (iCal link) is open to him. */
    calendar_feed: boolean;
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Mon planning', href: '/mon-planning' }];

export default function MyPlanning({ trainer, period, holidays, closures, seances, unavailabilities, calendar_feed, status }: Props) {
    const [open, setOpen] = useState<PlanningSeance | null>(null);
    const [attendance, setAttendance] = useState<string | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Mon planning" />

            <div className="flex flex-col gap-8 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Mon planning"
                        description={
                            trainer ? `${trainer.label} · formateur ${trainer.status_label.toLowerCase()}` : 'Vos séances et vos indisponibilités.'
                        }
                    />
                    {trainer !== null && calendar_feed && (
                        <Button asChild variant="outline">
                            <Link href={route('calendar.settings')}>
                                <CalendarPlus className="h-4 w-4" />
                                Abonner mon agenda
                            </Link>
                        </Button>
                    )}
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                {trainer === null ? (
                    <p className="text-sm">
                        Votre compte n'est lié à aucune fiche formateur. Demandez à la personne qui gère les formateurs de le lier à votre fiche.
                    </p>
                ) : (
                    <>
                        <section className="flex flex-col gap-3" aria-labelledby="seances_title">
                            <h2 id="seances_title" className="text-lg font-semibold">
                                Mes séances
                            </h2>
                            <p className="text-muted-foreground text-sm">Ouvrez une séance pour noter les présences.</p>
                            <PlanningBoard
                                period={period}
                                seances={seances}
                                holidays={holidays}
                                closures={closures}
                                showTrainer={false}
                                onNavigate={(view, date) =>
                                    router.get(
                                        route('my-planning.show'),
                                        { view, date },
                                        { preserveState: true, preserveScroll: true, replace: true },
                                    )
                                }
                                // His séance opens its attendance straight away; otherwise its details.
                                onOpen={(s) => (s.can_attendance ? setAttendance(s.id) : setOpen(s))}
                            />
                        </section>

                        <section className="flex flex-col gap-3" aria-labelledby="unavailabilities_title">
                            <h2 id="unavailabilities_title" className="text-lg font-semibold">
                                Mes indisponibilités
                            </h2>
                            <p className="text-muted-foreground text-sm">Signalez les périodes où vous ne pouvez pas assurer de séance.</p>
                            <Unavailabilities
                                rows={unavailabilities}
                                storeUrl={route('my-planning.unavailabilities.store')}
                                destroyUrl={(id) => route('my-planning.unavailabilities.destroy', id)}
                            />
                        </section>
                    </>
                )}
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
