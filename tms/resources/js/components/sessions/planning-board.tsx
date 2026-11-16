import type { Alert } from '@/components/sessions/session-modules';
import { Button } from '@/components/ui/button';
import { formatDay } from '@/lib/crm';
import { addDays, dayShade, isoWeekday, monthGrid, WEEKDAYS_SHORT, type ClosureRow } from '@/lib/sessions';
import { AlertTriangle, ChevronLeft, ChevronRight } from 'lucide-react';
import { useMemo } from 'react';

/** A séance of a planning page (PlanningRows). */
export interface PlanningSeance {
    id: string;
    date: string;
    start: string;
    end: string;
    minutes: number;
    duration: string;
    session: { id: string; label: string; cancelled: boolean; href: string | null };
    module: string;
    trainer: { id: string; label: string } | null;
    room: { id: string; name: string; address: string | null } | null;
    place_text: string | null;
    status: 'planned' | 'cancelled';
    can_attendance: boolean;
    /** Planners only. */
    cancel_reason?: string | null;
    note?: string | null;
    alerts?: Alert[];
}

/** The period of a planning page (PlanningPeriod). */
export interface PlanningPeriodData {
    view: 'month' | 'week';
    date: string;
    month: string;
    from: string;
    to: string;
    label: string;
    prev: string;
    next: string;
    today: string;
}

/** Where a séance takes place: its room, else its free place. */
export function seancePlace(s: PlanningSeance): string | null {
    if (s.room) return s.room.address ? `${s.room.name} (${s.room.address})` : s.room.name;

    return s.place_text;
}

function SeanceButton({
    seance,
    tall,
    showTrainer,
    onOpen,
}: {
    seance: PlanningSeance;
    tall: boolean;
    showTrainer: boolean;
    onOpen: (s: PlanningSeance) => void;
}) {
    const cancelled = seance.status === 'cancelled' || seance.session.cancelled;
    const alerts = seance.alerts ?? [];

    return (
        <button
            type="button"
            onClick={() => onOpen(seance)}
            title={[
                `${seance.start}–${seance.end} · ${seance.session.label}`,
                seance.module,
                showTrainer ? seance.trainer?.label : null,
                seancePlace(seance),
                cancelled ? 'Annulée' : null,
                ...alerts.map((a) => a.message),
            ]
                .filter(Boolean)
                .join('\n')}
            className={`bg-primary/10 hover:bg-primary/20 grid w-full grid-cols-[minmax(0,1fr)] overflow-hidden rounded border-l-4 border-l-blue-500 px-1 text-left text-xs ${cancelled ? 'line-through opacity-60' : ''}`}
        >
            <span className="block truncate">
                {alerts.length > 0 && <AlertTriangle className="mr-0.5 inline h-3 w-3 text-amber-600" aria-label="Alertes" />}
                {seance.start}
                {tall ? `–${seance.end}` : ''} {seance.session.label}
            </span>
            {tall && (
                <span className="text-muted-foreground block truncate">
                    {[seance.module, showTrainer ? seance.trainer?.label : null, seancePlace(seance)].filter(Boolean).join(' · ')}
                </span>
            )}
        </button>
    );
}

/**
 * The séances of a period as a month or week grid (weekends, public holidays
 * and closures shaded and labelled), a list of days on a phone. The period
 * is the server's: moving it reloads the page (onNavigate).
 */
export function PlanningBoard({
    period,
    seances,
    holidays,
    closures,
    showTrainer,
    onNavigate,
    onOpen,
}: {
    period: PlanningPeriodData;
    seances: PlanningSeance[];
    holidays: Record<string, string>;
    closures: ClosureRow[];
    showTrainer: boolean;
    onNavigate: (view: 'month' | 'week', date: string) => void;
    onOpen: (s: PlanningSeance) => void;
}) {
    const byDay = useMemo(() => {
        const days: Record<string, PlanningSeance[]> = {};
        seances.forEach((s) => (days[s.date] ??= []).push(s));

        return days;
    }, [seances]);
    const week = Array.from({ length: 7 }, (_, i) => addDays(period.from, i));
    const visibleDays = (
        period.view === 'month'
            ? monthGrid(period.month)
                  .flat()
                  .filter((d) => d.inMonth)
                  .map((d) => d.day)
            : week
    ).filter((d) => (byDay[d] ?? []).length > 0);

    const cell = (day: string, inMonth: boolean, tall: boolean) => {
        const shade = dayShade(day, holidays, closures);

        return (
            <td
                key={day}
                className={`border-l p-1 align-top first:border-l-0 ${tall ? 'h-56' : 'h-28'} ${shade.off || !inMonth ? 'bg-muted/40' : ''} ${inMonth ? '' : 'text-muted-foreground'}`}
            >
                <div className="mb-1 flex items-start justify-between gap-1 text-xs">
                    <span className={day === period.today ? 'bg-primary text-primary-foreground rounded px-1 font-semibold' : ''}>
                        {tall ? `${WEEKDAYS_SHORT[isoWeekday(day) - 1]} ${formatDay(day)}` : Number(day.slice(8))}
                    </span>
                    {shade.label && (
                        <span className="text-muted-foreground truncate text-[10px] italic" title={shade.label}>
                            {shade.label}
                        </span>
                    )}
                </div>
                <ul className="grid gap-1">
                    {(byDay[day] ?? []).map((s) => (
                        <li key={s.id}>
                            <SeanceButton seance={s} tall={tall} showTrainer={showTrainer} onOpen={onOpen} />
                        </li>
                    ))}
                </ul>
            </td>
        );
    };

    return (
        <section aria-label="Planning des séances" className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <Button variant="outline" size="icon" aria-label="Précédent" onClick={() => onNavigate(period.view, period.prev)}>
                        <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <h2 className="min-w-48 text-center text-sm font-semibold">{period.label}</h2>
                    <Button variant="outline" size="icon" aria-label="Suivant" onClick={() => onNavigate(period.view, period.next)}>
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                    {(period.today < period.from || period.today > period.to) && (
                        <Button variant="ghost" size="sm" onClick={() => onNavigate(period.view, period.today)}>
                            Aujourd'hui
                        </Button>
                    )}
                </div>
                <div className="flex gap-1" role="group" aria-label="Vue">
                    <Button
                        size="sm"
                        variant={period.view === 'month' ? 'default' : 'outline'}
                        aria-pressed={period.view === 'month'}
                        onClick={() => onNavigate('month', period.date)}
                    >
                        Mois
                    </Button>
                    <Button
                        size="sm"
                        variant={period.view === 'week' ? 'default' : 'outline'}
                        aria-pressed={period.view === 'week'}
                        onClick={() => onNavigate('week', period.date)}
                    >
                        Semaine
                    </Button>
                </div>
            </div>

            <div className="hidden overflow-x-auto rounded-xl border md:block">
                <table className="w-full min-w-[42rem] table-fixed text-sm">
                    <thead className="bg-muted/50">
                        <tr>
                            {WEEKDAYS_SHORT.map((d) => (
                                <th key={d} className="px-2 py-1 text-left font-medium">
                                    {d}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {period.view === 'month' ? (
                            monthGrid(period.month).map((w) => (
                                <tr key={w[0].day} className="border-t">
                                    {w.map(({ day, inMonth }) => cell(day, inMonth, false))}
                                </tr>
                            ))
                        ) : (
                            <tr className="border-t">{week.map((day) => cell(day, true, true))}</tr>
                        )}
                    </tbody>
                </table>
            </div>

            {/* On a phone: the days holding a séance, one under the other. */}
            <div className="grid gap-3 md:hidden">
                {visibleDays.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border p-4 text-center text-sm">Aucune séance sur cette période.</p>
                ) : (
                    visibleDays.map((day) => {
                        const shade = dayShade(day, holidays, closures);

                        return (
                            <div key={day} className="rounded-xl border p-3">
                                <h3 className="mb-2 text-sm font-semibold">
                                    {WEEKDAYS_SHORT[isoWeekday(day) - 1]} {formatDay(day)}
                                    {shade.label && <span className="text-muted-foreground ml-2 text-xs font-normal italic">{shade.label}</span>}
                                </h3>
                                <ul className="grid gap-2">
                                    {(byDay[day] ?? []).map((s) => (
                                        <li key={s.id}>
                                            <SeanceButton seance={s} tall showTrainer={showTrainer} onOpen={onOpen} />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        );
                    })
                )}
            </div>
            <p className="text-muted-foreground text-xs">Fond grisé : week-end, jour férié ou fermeture · barré : séance annulée.</p>
        </section>
    );
}
