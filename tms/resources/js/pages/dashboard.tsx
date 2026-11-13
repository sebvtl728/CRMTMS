import { OPEN_EVENT } from '@/components/crm/global-search';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Contact, Factory, Search, UserPlus } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Accueil', href: '/dashboard' }];

interface RecentView {
    type: 'organisation' | 'person';
    id: string;
    name: string;
    subtitle: string | null;
    url: string;
}

interface Props {
    recent_views: RecentView[];
    counters: { organisations: number; people: number } | null;
}

const numberFormat = new Intl.NumberFormat('fr-FR');

export default function Dashboard({ recent_views, counters }: Props) {
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
                            <Search /> Rechercher <kbd className="text-muted-foreground ml-1 hidden rounded border px-1 text-xs sm:inline">Ctrl K</kbd>
                        </Button>
                    </section>
                )}

                {counters !== null && (
                    <section aria-label="Chiffres" className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Link href="/crm/organisations" className="focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none">
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
                        {/* « Mes relances » arrives with the pipeline (lot 1c). */}
                    </section>
                )}

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
