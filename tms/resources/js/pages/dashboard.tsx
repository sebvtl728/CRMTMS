import { OPEN_EVENT } from '@/components/crm/global-search';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatAmount } from '@/lib/crm';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarClock, Contact, Factory, Search, UserPlus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Accueil', href: '/dashboard' }];

interface RecentView {
    type: 'organisation' | 'person';
    id: string;
    name: string;
    subtitle: string | null;
    url: string;
}

interface Fiche {
    id: string;
    name: string;
}

interface Meeting {
    id: string;
    type: string | null;
    at: string;
    day: string;
    time: string;
    location: string | null;
    organisation: Fiche | null;
    person: Fiche | null;
    opportunity: Fiche | null;
}

interface Commercial {
    follow_ups: { overdue: number; today: number };
    pipeline: { weighted_amount: string; count: number; month: string; close_from: string; close_to: string };
    /** Quotes sent this month, accepted among them; rate in %, null when none was sent. */
    quotes: { sent: number; accepted: number; rate: number | null; month: string };
    meetings: Meeting[];
}

interface Props {
    recent_views: RecentView[];
    counters: { organisations: number; people: number } | null;
    commercial: Commercial | null;
}

/** The page of the meeting's fiche: the person, else the entreprise, else the opportunity. */
function meetingUrl(meeting: Meeting): string {
    if (meeting.person) return route('crm.people.show', meeting.person.id);
    if (meeting.organisation) return route('crm.organisations.show', meeting.organisation.id);
    if (meeting.opportunity) return route('crm.opportunities.show', meeting.opportunity.id);

    return route('crm.planning');
}

function NextMeetings({ meetings }: { meetings: Meeting[] }) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
                <CardTitle className="text-base">Prochains rendez-vous</CardTitle>
                <Link href={route('crm.planning')} className="text-sm underline">
                    Planning
                </Link>
            </CardHeader>
            <CardContent>
                {meetings.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Aucun rendez-vous prévu.</p>
                ) : (
                    <ul className="divide-y">
                        {meetings.map((meeting) => (
                            <li key={meeting.id}>
                                <Link href={meetingUrl(meeting)} className="hover:bg-muted/50 flex items-start gap-3 rounded-md px-2 py-3">
                                    <CalendarClock className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden />
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-medium break-words">
                                            {meeting.person?.name ?? meeting.organisation?.name ?? meeting.opportunity?.name ?? '—'}
                                        </span>
                                        <span className="text-muted-foreground block text-sm break-words">
                                            {[meeting.type, meeting.location].filter(Boolean).join(' · ')}
                                        </span>
                                    </span>
                                    <span className="text-muted-foreground shrink-0 text-right text-sm">
                                        {meeting.day}
                                        <br />
                                        {meeting.time}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

const numberFormat = new Intl.NumberFormat('fr-FR');
const percentFormat = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });

export default function Dashboard({ recent_views, counters, commercial }: Props) {
    const { auth } = usePage<SharedData>().props;
    const can = (permission: string) => auth.permissions.includes(permission);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Accueil" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <Heading title="Accueil" description="Vos raccourcis et les dernières fiches que vous avez consultées." />

                {can('crm.view') && (
                    <section aria-label="Raccourcis" className="flex flex-wrap gap-2">
                        {can('crm.create') && (
                            <>
                                <Button asChild>
                                    <Link href="/crm/organisations/create">
                                        <Factory /> Nouvelle entreprise
                                    </Link>
                                </Button>
                                <Button asChild variant="secondary">
                                    <Link href="/crm/people/create">
                                        <UserPlus /> Nouveau particulier
                                    </Link>
                                </Button>
                            </>
                        )}
                        <Button variant="outline" onClick={() => window.dispatchEvent(new Event(OPEN_EVENT))}>
                            <Search /> Rechercher{' '}
                            <kbd className="text-muted-foreground ml-1 hidden rounded border px-1 text-xs sm:inline">Ctrl K</kbd>
                        </Button>
                    </section>
                )}

                {counters !== null && (
                    <section aria-label="Chiffres" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                        <Link
                            href="/crm/organisations"
                            className="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <Card className="hover:bg-muted/50 h-full transition-colors">
                                <CardHeader>
                                    <CardDescription>Entreprises</CardDescription>
                                    <CardTitle className="text-3xl">{numberFormat.format(counters.organisations)}</CardTitle>
                                </CardHeader>
                            </Card>
                        </Link>
                        <Link href="/crm/people" className="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none">
                            <Card className="hover:bg-muted/50 h-full transition-colors">
                                <CardHeader>
                                    <CardDescription>Personnes</CardDescription>
                                    <CardTitle className="text-3xl">{numberFormat.format(counters.people)}</CardTitle>
                                </CardHeader>
                            </Card>
                        </Link>
                        {commercial !== null && (
                            <section aria-label="Mes relances">
                                <Link
                                    href={route('crm.todo')}
                                    className="focus-visible:ring-ring block h-full rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    <Card className="hover:bg-muted/50 h-full transition-colors">
                                        <CardHeader>
                                            <CardDescription>Mes relances</CardDescription>
                                            <CardTitle className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                                                <span className={commercial.follow_ups.overdue > 0 ? 'text-red-600 dark:text-red-400' : ''}>
                                                    <span className="text-3xl">{numberFormat.format(commercial.follow_ups.overdue)}</span>{' '}
                                                    <span className="text-sm font-normal">en retard</span>
                                                </span>
                                                <span>
                                                    <span className="text-3xl">{numberFormat.format(commercial.follow_ups.today)}</span>{' '}
                                                    <span className="text-sm font-normal">aujourd'hui</span>
                                                </span>
                                            </CardTitle>
                                        </CardHeader>
                                    </Card>
                                </Link>
                            </section>
                        )}
                        {commercial !== null && (
                            <section aria-label="Pipeline pondéré du mois">
                                <Link
                                    href={`${route('crm.opportunities.index')}?${new URLSearchParams({ close_from: commercial.pipeline.close_from, close_to: commercial.pipeline.close_to })}`}
                                    className="focus-visible:ring-ring block h-full rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    <Card className="hover:bg-muted/50 h-full transition-colors">
                                        <CardHeader>
                                            <CardDescription>Pipeline pondéré · {commercial.pipeline.month}</CardDescription>
                                            <CardTitle className="text-3xl">{formatAmount(commercial.pipeline.weighted_amount)}</CardTitle>
                                            <CardDescription>
                                                {numberFormat.format(commercial.pipeline.count)} opportunité{commercial.pipeline.count > 1 ? 's' : ''}{' '}
                                                en cours, clôture prévue ce mois-ci
                                            </CardDescription>
                                        </CardHeader>
                                    </Card>
                                </Link>
                            </section>
                        )}
                        {commercial !== null && (
                            <section aria-label="Devis du mois">
                                <Link
                                    href={route('crm.quotes.index')}
                                    className="focus-visible:ring-ring block h-full rounded-xl focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    <Card className="hover:bg-muted/50 h-full transition-colors">
                                        <CardHeader>
                                            <CardDescription>Devis · {commercial.quotes.month}</CardDescription>
                                            <CardTitle className="text-3xl">
                                                {commercial.quotes.rate === null ? '—' : `${percentFormat.format(commercial.quotes.rate)} %`}
                                            </CardTitle>
                                            <CardDescription>
                                                {numberFormat.format(commercial.quotes.sent)} envoyé{commercial.quotes.sent > 1 ? 's' : ''},{' '}
                                                {numberFormat.format(commercial.quotes.accepted)} accepté{commercial.quotes.accepted > 1 ? 's' : ''}
                                            </CardDescription>
                                        </CardHeader>
                                    </Card>
                                </Link>
                            </section>
                        )}
                    </section>
                )}

                {commercial !== null && <NextMeetings meetings={commercial.meetings} />}

                {counters !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Dernières fiches consultées</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recent_views.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Les fiches que vous ouvrirez apparaîtront ici.</p>
                            ) : (
                                <ul className="divide-y">
                                    {recent_views.map((view) => (
                                        <li key={`${view.type}-${view.id}`}>
                                            <Link href={view.url} className="hover:bg-muted/50 flex items-center gap-3 rounded-md px-2 py-3">
                                                {view.type === 'organisation' ? (
                                                    <Factory className="text-muted-foreground size-4 shrink-0" aria-hidden />
                                                ) : (
                                                    <Contact className="text-muted-foreground size-4 shrink-0" aria-hidden />
                                                )}
                                                <span className="min-w-0 flex-1 truncate font-medium">{view.name}</span>
                                                {view.subtitle && <span className="text-muted-foreground truncate text-sm">{view.subtitle}</span>}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
