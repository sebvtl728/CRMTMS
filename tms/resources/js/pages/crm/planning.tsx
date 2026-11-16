import { editActivity } from '@/components/crm/quick-action';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight, ClipboardPen, MapPin, Pencil, Phone } from 'lucide-react';
import { useState } from 'react';

interface Fiche {
    id: string;
    name: string;
}

interface PlanningItem {
    id: string;
    kind: 'meeting' | 'call';
    type: string | null;
    subject: string;
    purpose: string | null;
    time: string;
    duration_minutes: number | null;
    location: string | null;
    responsible: string | null;
    status: 'todo' | 'overdue' | 'done';
    status_label: string;
    organisation: Fiche | null;
    person: Fiche | null;
    opportunity: Fiche | null;
    /** Compte rendu and « Modifier »: scope of crm.activities.update. */
    can_update: boolean;
    /** The viewer may open the fiche (an action handed to him may hang on a fiche out of his scope). */
    fiche_visible: boolean;
}

interface PlanningDay {
    date: string;
    label: string;
    items: PlanningItem[];
}

interface PlanningProps {
    week: string;
    previous: string;
    next: string;
    current_week: string;
    today: string;
    days: PlanningDay[];
    user: string;
    user_name: string;
    users: Option[];
    can_team: boolean;
    can_report: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Planning', href: '/crm/planning' }];

/** « 1 h 30 », « 45 min ». */
function formatDuration(minutes: number | null): string | null {
    if (!minutes) return null;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    if (h === 0) return `${m} min`;

    return m === 0 ? `${h} h` : `${h} h ${String(m).padStart(2, '0')}`;
}

/** The page of the fiche: opportunity first, then person, then entreprise. */
function ficheUrl(item: PlanningItem): string | null {
    if (item.opportunity) return route('crm.opportunities.show', item.opportunity.id);
    if (item.person) return route('crm.people.show', item.person.id);
    if (item.organisation) return route('crm.organisations.show', item.organisation.id);

    return null;
}

function Item({ item, canReport, today, day }: { item: PlanningItem; canReport: boolean; today: string; day: string }) {
    const url = item.fiche_visible ? ficheUrl(item) : null;
    const fiche = item.person?.name ?? item.organisation?.name ?? item.opportunity?.name;
    const duration = formatDuration(item.duration_minutes);
    const Icon = item.kind === 'call' ? Phone : CalendarDays;
    const editable = canReport && item.can_update;
    const reportable = editable && item.kind === 'meeting' && item.status !== 'done' && day === today;

    return (
        <li
            className={cn(
                'grid gap-1 rounded-lg border p-2 text-sm',
                item.status === 'done' && 'bg-muted/50',
                item.status === 'overdue' && 'border-red-300 dark:border-red-900',
            )}
        >
            <div className="flex items-center gap-2">
                <Icon className="text-muted-foreground h-4 w-4 shrink-0" aria-hidden />
                <span className="font-semibold tabular-nums">{item.time}</span>
                {duration && <span className="text-muted-foreground text-xs whitespace-nowrap">{duration}</span>}
                {item.status === 'done' ? (
                    <Badge variant="secondary" className="ml-auto">
                        Fait
                    </Badge>
                ) : (
                    item.status === 'overdue' && (
                        <Badge variant="destructive" className="ml-auto">
                            {item.status_label}
                        </Badge>
                    )
                )}
            </div>
            {url ? (
                <Link href={url} className="font-medium break-words hover:underline">
                    {fiche ?? item.subject}
                </Link>
            ) : (
                <span className="font-medium break-words">{fiche ?? item.subject}</span>
            )}
            <span className="text-muted-foreground text-xs break-words">
                {item.type}
                {item.opportunity && fiche !== item.opportunity.name && ` · ${item.opportunity.name}`}
            </span>
            {item.purpose && (
                <span className="line-clamp-2 text-xs break-words" title={item.purpose}>
                    <span className="text-muted-foreground">Motif : </span>
                    {item.purpose}
                </span>
            )}
            {item.location && (
                <span className="text-muted-foreground flex items-center gap-1 text-xs break-words">
                    <MapPin className="h-3 w-3 shrink-0" aria-hidden />
                    {item.location}
                </span>
            )}
            {(reportable || editable) && (
                <div className="mt-1 flex flex-wrap gap-1">
                    {reportable && (
                        <Button asChild size="sm">
                            <Link href={`${route('crm.report')}?${new URLSearchParams({ activity: item.id })}`}>
                                <ClipboardPen className="h-4 w-4" />
                                Compte rendu
                            </Link>
                        </Button>
                    )}
                    {editable && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => editActivity(item.id)}
                            aria-label={`Modifier l’action ${item.subject}`}
                        >
                            <Pencil className="h-4 w-4" />
                            Modifier
                        </Button>
                    )}
                </div>
            )}
        </li>
    );
}

function DayItems({ day, canReport, today }: { day: PlanningDay; canReport: boolean; today: string }) {
    if (day.items.length === 0) return <p className="text-muted-foreground text-sm">Rien de prévu.</p>;

    return (
        <ul className="grid gap-2">
            {day.items.map((item) => (
                <Item key={item.id} item={item} canReport={canReport} today={today} day={day.date} />
            ))}
        </ul>
    );
}

export default function Planning(props: PlanningProps) {
    const { week, previous, next, current_week, today, days, user, user_name, users, can_team, can_report } = props;
    const initial = Math.max(
        0,
        days.findIndex((d) => d.date === today),
    );
    const [dayIndex, setDayIndex] = useState(initial);
    const day = days[dayIndex] ?? days[0];

    const go = (params: { week?: string; user?: string }) => {
        const query: Record<string, string> = { week: params.week ?? week };
        const who = params.user ?? user;
        if (can_team) query.user = who;
        router.get(route('crm.planning'), query, { preserveScroll: true, onSuccess: () => setDayIndex(0) });
    };

    const first = days[0]?.date ?? '';
    const last = days[6]?.date ?? '';
    const range = `${new Date(`${first}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })} – ${new Date(`${last}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Planning" />

            <div className="flex min-w-0 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Planning" description={`Semaine ${week.slice(-2)} · ${range}${can_team ? ` · ${user_name}` : ''}`} />
                    {can_report && (
                        <Button asChild>
                            <Link href={route('crm.report')}>
                                <ClipboardPen className="h-4 w-4" />
                                Compte rendu de RDV
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap items-end gap-2">
                    <Button type="button" variant="outline" size="sm" onClick={() => go({ week: previous })} aria-label="Semaine précédente">
                        <ChevronLeft className="h-4 w-4" />
                        <span className="hidden sm:inline">Semaine précédente</span>
                    </Button>
                    {week !== current_week && (
                        <Button type="button" variant="outline" size="sm" onClick={() => go({ week: current_week })}>
                            Cette semaine
                        </Button>
                    )}
                    <Button type="button" variant="outline" size="sm" onClick={() => go({ week: next })} aria-label="Semaine suivante">
                        <span className="hidden sm:inline">Semaine suivante</span>
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                    {can_team && users.length > 0 && (
                        <div className="ml-auto grid gap-1">
                            <Label htmlFor="planning-user" className="text-xs">
                                Collègue
                            </Label>
                            <Select value={user} onValueChange={(v) => go({ user: v })}>
                                <SelectTrigger id="planning-user" className="h-8 w-56 max-w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {users.map((u) => (
                                        <SelectItem key={u.value} value={u.value}>
                                            {u.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                </div>

                {/* Phone: one day at a time. */}
                <section className="grid gap-3 md:hidden" aria-label="Jour">
                    <div className="flex items-center justify-between gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Jour précédent"
                            disabled={dayIndex === 0}
                            onClick={() => setDayIndex(dayIndex - 1)}
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                        <h3 className={cn('text-base font-semibold', day?.date === today && 'text-primary')}>{day?.label}</h3>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Jour suivant"
                            disabled={dayIndex === days.length - 1}
                            onClick={() => setDayIndex(dayIndex + 1)}
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                    <div className="grid grid-cols-7 gap-1">
                        {days.map((d, i) => (
                            <button
                                key={d.date}
                                type="button"
                                aria-pressed={i === dayIndex}
                                aria-label={d.label}
                                onClick={() => setDayIndex(i)}
                                className={cn(
                                    'flex flex-col items-center rounded-md border py-1 text-xs',
                                    i === dayIndex ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-muted',
                                )}
                            >
                                <span>{d.label.slice(0, 3)}</span>
                                <span className="font-semibold">{Number(d.date.slice(8))}</span>
                                {d.items.length > 0 && <span aria-hidden>•</span>}
                            </button>
                        ))}
                    </div>
                    {day && <DayItems day={day} canReport={can_report} today={today} />}
                </section>

                {/* Computer: the whole week. */}
                <div className="hidden gap-2 md:grid md:grid-cols-7">
                    {days.map((d) => (
                        <section key={d.date} aria-labelledby={`day-${d.date}`} className="grid min-w-0 content-start gap-2">
                            <h3 id={`day-${d.date}`} className={cn('text-sm font-semibold', d.date === today && 'text-primary')}>
                                {d.label}
                            </h3>
                            <DayItems day={d} canReport={can_report} today={today} />
                        </section>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
