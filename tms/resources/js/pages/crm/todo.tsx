import { ActivityNotes } from '@/components/crm/activity-notes';
import { assignActivity, completeActivity, editActivity, shiftDay } from '@/components/crm/quick-action';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, todayIso } from '@/lib/crm';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarClock, Check, ExternalLink, Pencil, UserRoundPlus, Users } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Fiche {
    id: string;
    name: string;
}

interface TodoRow {
    id: string;
    type: string | null;
    subject: string;
    purpose: string | null;
    next_steps: string | null;
    due_at: string | null;
    responsible: string | null;
    collaborators?: { id: string; name: string }[];
    status: 'todo' | 'overdue' | 'done';
    status_label: string;
    organisation: Fiche | null;
    person: Fiche | null;
    opportunity: Fiche | null;
    /** « Fait », « Reporter », « Modifier » and « Confier à… »: scope of crm.activities.update. */
    can_update: boolean;
    /** The viewer may open the fiche (an action handed to him may hang on a fiche out of his scope). */
    fiche_visible: boolean;
}

interface TodoProps {
    sections: { overdue: TodoRow[]; today: TodoRow[]; week: TodoRow[] };
    team: boolean;
    can_team: boolean;
    can_edit: boolean;
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'À faire', href: '/crm/todo' }];

const SECTIONS = [
    { key: 'overdue', title: 'En retard', empty: 'Rien en retard.', tone: 'text-red-600 dark:text-red-400' },
    { key: 'today', title: "Aujourd'hui", empty: "Rien de prévu aujourd'hui.", tone: '' },
    { key: 'week', title: 'Cette semaine', empty: 'Rien d’autre cette semaine.', tone: '' },
] as const;

/** The page of the fiche an action hangs on: opportunity first, then person, then entreprise. */
function ficheUrl(row: TodoRow): string | null {
    if (row.opportunity) return route('crm.opportunities.show', row.opportunity.id);
    if (row.person) return route('crm.people.show', row.person.id);
    if (row.organisation) return route('crm.organisations.show', row.organisation.id);

    return null;
}

/** « Reporter »: tomorrow, in a week, or a chosen date (a date alone means 9:00, Paris). */
function Postpone({ activityId }: { activityId: string }) {
    const [picking, setPicking] = useState(false);
    const [date, setDate] = useState('');
    const [error, setError] = useState<string | null>(null);

    const postpone = (due_at: string) =>
        router.patch(
            route('crm.activities.postpone', activityId),
            { due_at },
            {
                preserveScroll: true,
                onSuccess: () => setPicking(false),
                onError: (errors) => setError(errors.due_at ?? 'Report impossible.'),
            },
        );

    if (picking) {
        return (
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    type="date"
                    aria-label="Nouvelle échéance"
                    className="h-8 w-40"
                    min={todayIso()}
                    value={date}
                    onChange={(e) => {
                        setDate(e.target.value);
                        setError(null);
                    }}
                />
                <Button type="button" size="sm" disabled={date === ''} onClick={() => postpone(date)}>
                    Reporter
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setPicking(false)}>
                    Annuler
                </Button>
                {error && (
                    <p role="alert" className="w-full text-sm text-red-600 dark:text-red-400">
                        {error}
                    </p>
                )}
            </div>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <CalendarClock className="h-4 w-4" />
                    Reporter
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={() => postpone(shiftDay(todayIso(), 1))}>Demain</DropdownMenuItem>
                <DropdownMenuItem onSelect={() => postpone(shiftDay(todayIso(), 7))}>Dans 1 semaine</DropdownMenuItem>
                <DropdownMenuItem onSelect={() => setPicking(true)}>Choisir une date…</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function TodoItem({ row, canEdit, team }: { row: TodoRow; canEdit: boolean; team: boolean }) {
    const url = row.fiche_visible ? ficheUrl(row) : null;
    const fiches = [row.organisation, row.person, row.opportunity].filter((f): f is Fiche => f !== null);

    return (
        <li
            className={cn('flex flex-wrap items-start gap-3 rounded-lg border p-3', row.status === 'overdue' && 'border-red-300 dark:border-red-900')}
        >
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium">{row.subject}</span>
                    {row.type && <Badge variant="outline">{row.type}</Badge>}
                    {row.status === 'overdue' && <Badge variant="destructive">{row.status_label}</Badge>}
                </div>
                <p className="text-muted-foreground text-xs">
                    Échéance : {formatDateTime(row.due_at)}
                    {fiches.length > 0 && ` · ${fiches.map((f) => f.name).join(' · ')}`}
                    {team && row.responsible && ` · ${row.responsible}`}
                    {row.collaborators && row.collaborators.length > 0 && ` · avec ${row.collaborators.map((c) => c.name).join(', ')}`}
                </p>
                <ActivityNotes purpose={row.purpose} nextSteps={row.next_steps} />
            </div>
            <div className="flex flex-wrap items-center gap-2">
                {canEdit && row.can_update && (
                    <>
                        <Button type="button" size="sm" onClick={() => completeActivity(row.id)}>
                            <Check className="h-4 w-4" />
                            Fait
                        </Button>
                        <Postpone activityId={row.id} />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => editActivity(row.id)}
                            aria-label={`Modifier l’action ${row.subject}`}
                        >
                            <Pencil className="h-4 w-4" />
                            Modifier
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => assignActivity(row.id)}
                            aria-label={`Confier l’action ${row.subject}`}
                        >
                            <UserRoundPlus className="h-4 w-4" />
                            Confier à…
                        </Button>
                    </>
                )}
                {url && (
                    <Button asChild variant="ghost" size="sm">
                        <Link href={url}>
                            <ExternalLink className="h-4 w-4" />
                            Ouvrir la fiche
                        </Link>
                    </Button>
                )}
            </div>
        </li>
    );
}

export default function Todo({ sections, team, can_team, can_edit, status }: TodoProps) {
    const toggleTeam = () => router.get(route('crm.todo'), team ? {} : { team: 1 }, { preserveState: true, replace: true });
    const total = sections.overdue.length + sections.today.length + sections.week.length;

    // Link of the « Action confiée » e-mail (?action=…): opens the action.
    useEffect(() => {
        const id = new URLSearchParams(window.location.search).get('action');
        if (!id) return;
        const timer = window.setTimeout(() => editActivity(id), 0);

        return () => window.clearTimeout(timer);
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="À faire" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="À faire" description={team ? "Les actions à faire de l'équipe" : 'Vos actions à faire'} />
                    {can_team && (
                        <Button type="button" variant={team ? 'default' : 'outline'} aria-pressed={team} onClick={toggleTeam}>
                            <Users className="h-4 w-4" />
                            Équipe
                        </Button>
                    )}
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                {total === 0 && <p className="text-muted-foreground text-sm">Aucune action à faire pour le moment.</p>}

                {SECTIONS.map(({ key, title, empty, tone }) => (
                    <section key={key} aria-labelledby={`todo-${key}`} className="grid gap-2">
                        <h3 id={`todo-${key}`} className={cn('text-base font-semibold', tone)}>
                            {title} ({sections[key].length})
                        </h3>
                        {sections[key].length === 0 ? (
                            <p className="text-muted-foreground text-sm">{empty}</p>
                        ) : (
                            <ul className="grid gap-2">
                                {sections[key].map((row) => (
                                    <TodoItem key={row.id} row={row} canEdit={can_edit} team={team} />
                                ))}
                            </ul>
                        )}
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}
