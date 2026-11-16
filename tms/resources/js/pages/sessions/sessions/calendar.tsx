import { FilterSelect } from '@/components/crm/filter-select';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { sessionDates, type SessionRow } from '@/lib/sessions';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, List, Plus } from 'lucide-react';

interface CalendarProps {
    /** YYYY-MM */
    month: string;
    /** « Décembre 2026 » */
    label: string;
    prev: string;
    next: string;
    /** Paris day, YYYY-MM-DD */
    today: string;
    sessions: SessionRow[];
    filters: { kind: string | null; modality: string | null; das: string | null };
    options: { kind: Option[]; modality: Option[]; das: Option[] };
    can: { create: boolean };
}

const WEEKDAYS = ['Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.', 'Dim.'];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sessions', href: '/sessions' },
    { title: 'Calendrier', href: '/sessions/calendar' },
];

const pad = (n: number) => String(n).padStart(2, '0');

/**
 * Days of the weeks covering the month (Monday first), as YYYY-MM-DD strings
 * computed without time zone (a calendar day, not an instant).
 */
function monthGrid(month: string): { day: string; inMonth: boolean }[][] {
    const [y, m] = month.split('-').map(Number);
    const daysInMonth = new Date(Date.UTC(y, m, 0)).getUTCDate();
    const firstWeekday = (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7;
    const cells: { day: string; inMonth: boolean }[] = [];
    for (let i = -firstWeekday; cells.length < Math.ceil((firstWeekday + daysInMonth) / 7) * 7; i++) {
        const date = new Date(Date.UTC(y, m - 1, 1 + i));
        cells.push({
            day: `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`,
            inMonth: i >= 0 && i < daysInMonth,
        });
    }
    const weeks: { day: string; inMonth: boolean }[][] = [];
    for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7));

    return weeks;
}

const statusClass: Record<SessionRow['status'], string> = {
    planned: 'border-l-slate-400',
    confirmed: 'border-l-blue-500',
    running: 'border-l-green-600',
    done: 'border-l-slate-300 opacity-70',
    cancelled: 'border-l-red-500 line-through opacity-60',
};

export default function SessionsCalendar({ month, label, prev, next, today, sessions, filters, options, can }: CalendarProps) {
    const visit = (changes: Partial<{ month: string; kind: string | null; modality: string | null; das: string | null }>) => {
        const query: Record<string, string> = {};
        const all = { month, ...filters, ...changes };
        (['month', 'kind', 'modality', 'das'] as const).forEach((k) => {
            if (all[k]) query[k] = String(all[k]);
        });
        router.get(route('sessions.calendar'), query, { preserveState: true, replace: true });
    };

    const weeks = monthGrid(month);
    const onDay = (day: string) => sessions.filter((s) => s.starts_on <= day && s.ends_on >= day);
    const thisMonth = today.slice(0, 7);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Sessions · ${label}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Calendrier des sessions" description="Sessions du mois, par dates" />
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={route('sessions.index')}>
                                <List className="h-4 w-4" />
                                Liste
                            </Link>
                        </Button>
                        {can.create && (
                            <Button asChild>
                                <Link href={route('sessions.create')}>
                                    <Plus className="h-4 w-4" />
                                    Nouvelle session
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="icon" aria-label="Mois précédent" onClick={() => visit({ month: prev })}>
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                        <h2 className="min-w-40 text-center text-lg font-semibold">{label}</h2>
                        <Button variant="outline" size="icon" aria-label="Mois suivant" onClick={() => visit({ month: next })}>
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                        {month !== thisMonth && (
                            <Button variant="ghost" size="sm" onClick={() => visit({ month: thisMonth })}>
                                Aujourd'hui
                            </Button>
                        )}
                    </div>
                    <div className="grid grid-cols-3 gap-3 sm:w-[32rem]">
                        <FilterSelect label="Type" value={filters.kind} options={options.kind} onChange={(v) => visit({ kind: v })} />
                        <FilterSelect label="Modalité" value={filters.modality} options={options.modality} onChange={(v) => visit({ modality: v })} />
                        <FilterSelect label="DAS" value={filters.das} options={options.das} onChange={(v) => visit({ das: v })} />
                    </div>
                </div>

                <div className="hidden overflow-x-auto rounded-xl border md:block">
                    <table className="w-full table-fixed text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                {WEEKDAYS.map((d) => (
                                    <th key={d} className="px-2 py-1 text-left font-medium">
                                        {d}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {weeks.map((week) => (
                                <tr key={week[0].day} className="border-t align-top">
                                    {week.map(({ day, inMonth }) => (
                                        <td
                                            key={day}
                                            className={`h-28 border-l p-1 first:border-l-0 ${inMonth ? '' : 'bg-muted/30 text-muted-foreground'}`}
                                        >
                                            <div
                                                className={`mb-1 text-xs ${day === today ? 'bg-primary text-primary-foreground inline-block rounded px-1' : ''}`}
                                            >
                                                {Number(day.slice(8))}
                                            </div>
                                            <ul className="grid gap-1">
                                                {onDay(day).map((s) => (
                                                    <li key={s.id}>
                                                        <Link
                                                            href={route('sessions.show', s.id)}
                                                            title={`${s.label} · ${sessionDates(s)} · ${s.status_label}`}
                                                            className={`bg-muted hover:bg-muted/70 block truncate rounded border-l-4 px-1 text-xs ${statusClass[s.status]}`}
                                                        >
                                                            {s.title ?? s.training?.title ?? s.number}
                                                        </Link>
                                                    </li>
                                                ))}
                                            </ul>
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <ul className="grid gap-2 md:hidden">
                    {sessions.length === 0 && (
                        <li className="text-muted-foreground rounded-xl border p-6 text-center text-sm">Aucune session ce mois-ci.</li>
                    )}
                    {sessions.map((s) => (
                        <li key={s.id} className={`rounded-xl border border-l-4 p-3 text-sm ${statusClass[s.status]}`}>
                            <Link href={route('sessions.show', s.id)} className="font-medium hover:underline">
                                {s.title ?? s.training?.title ?? s.number}
                            </Link>
                            <div className="text-muted-foreground text-xs">
                                {[s.number, sessionDates(s), s.kind_label, s.modality, s.organisation?.label, s.status_label]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </div>
                        </li>
                    ))}
                </ul>

                {sessions.length === 0 && <p className="text-muted-foreground hidden text-sm md:block">Aucune session ce mois-ci.</p>}
            </div>
        </AppLayout>
    );
}
