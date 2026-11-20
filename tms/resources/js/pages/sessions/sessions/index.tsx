import { FilterSelect } from '@/components/crm/filter-select';
import Heading from '@/components/heading';
import { SessionBoard, type BoardColumn } from '@/components/sessions/session-board';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type Option, type Paginated } from '@/lib/crm';
import { sessionDates, sessionLocation, sessionStatusVariant, type SessionRow } from '@/lib/sessions';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, CalendarDays, ChevronLeft, ChevronRight, Columns3, List, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Filters {
    q: string;
    status: string | null;
    kind: string | null;
    modality: string | null;
    das: string | null;
    training: string | null;
    responsible: string | null;
    from: string | null;
    to: string | null;
    trashed: boolean;
    sort: string;
    dir: 'asc' | 'desc';
}

type View = 'liste' | 'colonnes';

interface IndexProps {
    view: View;
    /** « Liste » only. */
    sessions: Paginated<SessionRow> | null;
    /** « Colonnes » only. */
    board: BoardColumn[] | null;
    /** « Afficher les annulées » (columns). */
    with_cancelled: boolean;
    filters: Filters;
    options: { status: Option[]; kind: Option[]; modality: Option[]; das: Option[]; training: Option[]; responsible: Option[]; sort: Option[] };
    can: { create: boolean; trash: boolean };
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sessions', href: '/sessions' }];

/** The last view chosen, remembered in this browser (storage may be refused). */
const VIEW_KEY = 'sessions.vue';

function rememberedView(): View | null {
    try {
        const value = window.localStorage.getItem(VIEW_KEY);

        return value === 'liste' || value === 'colonnes' ? value : null;
    } catch {
        return null;
    }
}

function rememberView(view: View): void {
    try {
        window.localStorage.setItem(VIEW_KEY, view);
    } catch {
        // Storage refused: the view is simply not remembered.
    }
}

function sessionsQuery(filters: Filters, view: View = 'liste', withCancelled = false): Record<string, string> {
    const query: Record<string, string> = {};
    if (view === 'colonnes') {
        query.vue = 'colonnes';
        if (withCancelled) query.annulees = '1';
    } else {
        query.vue = 'liste';
    }
    if (filters.q) query.q = filters.q;
    (['status', 'kind', 'modality', 'das', 'training', 'responsible', 'from', 'to'] as const).forEach((k) => {
        if (filters[k]) query[k] = String(filters[k]);
    });
    if (filters.trashed) query.trashed = '1';
    if (filters.sort !== 'starts_on' || filters.dir !== 'desc') {
        query.sort = filters.sort;
        query.dir = filters.dir;
    }

    return query;
}

function places(row: SessionRow): string {
    if (row.kind === 'individual') return '1 stagiaire';

    return row.kind === 'inter' && row.capacity !== null ? `${row.capacity} place${row.capacity > 1 ? 's' : ''}` : '-';
}

export default function SessionsIndex({ view, sessions, board, with_cancelled, filters, options, can, status }: IndexProps) {
    const [search, setSearch] = useState(filters.q);
    const first = useRef(true);

    const visit = (changes: Partial<Filters>) =>
        router.get(route('sessions.index'), sessionsQuery({ ...filters, ...changes }, view, with_cancelled), { preserveState: true, replace: true });

    const switchView = (next: View, withCancelled = with_cancelled) => {
        rememberView(next);
        router.get(route('sessions.index'), sessionsQuery(filters, next, withCancelled), { preserveState: true, replace: true });
    };

    // Opened without « vue »: the view chosen last time in this browser.
    useEffect(() => {
        if (new URLSearchParams(window.location.search).has('vue')) return;
        const remembered = rememberedView();
        if (remembered && remembered !== view) switchView(remembered);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /** The list filtered on a state (« Voir les N autres », « Voir toutes »). */
    const listHref = (state: string) => route('sessions.index', sessionsQuery({ ...filters, status: state }, 'liste'));

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => visit({ q: search }), 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const sortHeader = (column: string, label: string) => (
        <th className="px-3 py-2 font-medium" aria-sort={filters.sort === column ? (filters.dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
            <button
                type="button"
                onClick={() => visit({ sort: column, dir: filters.sort === column && filters.dir === 'asc' ? 'desc' : 'asc' })}
                className="inline-flex items-center gap-1 hover:underline"
            >
                {label}
                {filters.sort === column && (filters.dir === 'asc' ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />)}
            </button>
        </th>
    );

    const filtered = Boolean(
        filters.q ||
            filters.status ||
            filters.kind ||
            filters.modality ||
            filters.das ||
            filters.training ||
            filters.responsible ||
            filters.from ||
            filters.to ||
            filters.trashed,
    );

    const statusBadge = (row: SessionRow) => (
        <Badge variant={sessionStatusVariant(row.status)} className="whitespace-nowrap">
            {row.status_label}
        </Badge>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sessions" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading title="Sessions" description="Sessions de formation intra et inter, par date" />
                    <div className="flex flex-wrap items-start gap-2">
                        <div role="group" aria-label="Affichage" className="flex rounded-md border">
                            <Button
                                type="button"
                                variant={view === 'liste' ? 'secondary' : 'ghost'}
                                aria-pressed={view === 'liste'}
                                onClick={() => view !== 'liste' && switchView('liste')}
                            >
                                <List className="h-4 w-4" />
                                Liste
                            </Button>
                            <Button
                                type="button"
                                variant={view === 'colonnes' ? 'secondary' : 'ghost'}
                                aria-pressed={view === 'colonnes'}
                                onClick={() => view !== 'colonnes' && switchView('colonnes')}
                            >
                                <Columns3 className="h-4 w-4" />
                                Colonnes
                            </Button>
                        </div>
                        <Button asChild variant="outline">
                            <Link href={route('sessions.calendar')}>
                                <CalendarDays className="h-4 w-4" />
                                Calendrier
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

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <section aria-label="Filtres" className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-1 sm:col-span-2 lg:col-span-4">
                        <Label htmlFor="q" className="text-xs">
                            Recherche (numéro, formation, lieu)
                        </Label>
                        <Input
                            id="q"
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Ex. S-2026-0012, HACCP ou un intitulé"
                        />
                    </div>
                    {view === 'liste' && (
                        <FilterSelect label="État" value={filters.status} options={options.status} onChange={(v) => visit({ status: v })} />
                    )}
                    <FilterSelect label="Type" value={filters.kind} options={options.kind} onChange={(v) => visit({ kind: v })} />
                    <FilterSelect label="Modalité" value={filters.modality} options={options.modality} onChange={(v) => visit({ modality: v })} />
                    <FilterSelect label="DAS" value={filters.das} options={options.das} onChange={(v) => visit({ das: v })} />
                    <FilterSelect label="Formation" value={filters.training} options={options.training} onChange={(v) => visit({ training: v })} />
                    <FilterSelect
                        label="Responsable"
                        value={filters.responsible}
                        options={options.responsible}
                        onChange={(v) => visit({ responsible: v })}
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="from" className="text-xs">
                            Du
                        </Label>
                        <Input id="from" type="date" value={filters.from ?? ''} onChange={(e) => visit({ from: e.target.value || null })} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="to" className="text-xs">
                            Au
                        </Label>
                        <Input id="to" type="date" value={filters.to ?? ''} onChange={(e) => visit({ to: e.target.value || null })} />
                    </div>
                    <div className="flex items-end gap-3">
                        {view === 'colonnes' && (
                            <Label className="flex items-center gap-2 text-sm font-normal">
                                <Checkbox checked={with_cancelled} onCheckedChange={(v) => switchView('colonnes', v === true)} />
                                Afficher les annulées
                            </Label>
                        )}
                        {can.trash && (
                            <Label className="flex items-center gap-2 text-sm font-normal">
                                <Checkbox checked={filters.trashed} onCheckedChange={(v) => visit({ trashed: v === true })} />
                                Corbeille
                            </Label>
                        )}
                        {filtered && (
                            <Button type="button" variant="ghost" size="sm" onClick={() => router.get(route('sessions.index'), { vue: view })}>
                                Réinitialiser
                            </Button>
                        )}
                    </div>
                </section>

                {board && <SessionBoard columns={board} listHref={listHref} />}

                {sessions && (
                    <p className="text-sm">
                        {sessions.total} session{sessions.total > 1 ? 's' : ''}
                    </p>
                )}

                {!sessions ? null : sessions.data.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        {filtered ? 'Aucune session ne correspond à ces filtres.' : 'Aucune session pour le moment.'}
                    </div>
                ) : (
                    <>
                        <div className="hidden overflow-x-auto rounded-xl border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        {sortHeader('number', 'Numéro')}
                                        <th className="px-3 py-2 font-medium">Intitulé, formation</th>
                                        <th className="px-3 py-2 font-medium">Type</th>
                                        <th className="px-3 py-2 font-medium">Modalité</th>
                                        {sortHeader('starts_on', 'Dates')}
                                        <th className="px-3 py-2 font-medium">Lieu</th>
                                        <th className="px-3 py-2 font-medium">Places</th>
                                        <th className="px-3 py-2 font-medium">Responsable</th>
                                        <th className="px-3 py-2 font-medium">État</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {sessions.data.map((s) => (
                                        <tr key={s.id} className="hover:bg-muted/30 border-t">
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                <Link href={route('sessions.show', s.id)} className="font-medium hover:underline">
                                                    {s.number}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-2">
                                                {s.title && <div className="font-medium">{s.title}</div>}
                                                {s.training?.title ?? '-'}
                                                {s.das && <div className="text-muted-foreground text-xs">{s.das}</div>}
                                            </td>
                                            <td className="px-3 py-2">
                                                {s.kind_label}
                                                {s.organisation && <div className="text-muted-foreground text-xs">{s.organisation.label}</div>}
                                            </td>
                                            <td className="px-3 py-2">{s.modality ?? '-'}</td>
                                            <td className="px-3 py-2 whitespace-nowrap">{sessionDates(s)}</td>
                                            <td className="px-3 py-2">{sessionLocation(s)}</td>
                                            <td className="px-3 py-2 whitespace-nowrap">{places(s)}</td>
                                            <td className="px-3 py-2">{s.responsible ?? '-'}</td>
                                            <td className="px-3 py-2">{statusBadge(s)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="grid gap-2 md:hidden">
                            {sessions.data.map((s) => (
                                <li key={s.id} className="grid gap-1 rounded-xl border p-3 text-sm">
                                    <div className="flex items-center justify-between gap-2">
                                        <Link href={route('sessions.show', s.id)} className="font-medium hover:underline">
                                            {s.number}
                                        </Link>
                                        {statusBadge(s)}
                                    </div>
                                    {s.title && <div className="font-medium">{s.title}</div>}
                                    <div>{s.training?.title ?? '-'}</div>
                                    <div className="text-muted-foreground text-xs">
                                        {[s.kind_label, s.modality, s.organisation?.label, sessionDates(s), places(s)].filter(Boolean).join(' · ')}
                                    </div>
                                </li>
                            ))}
                        </ul>

                        <nav aria-label="Pagination" className="flex items-center justify-between gap-2 text-sm">
                            <span className="text-muted-foreground">
                                {sessions.from}–{sessions.to} sur {sessions.total}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!sessions.prev_page_url}
                                    onClick={() => sessions.prev_page_url && router.get(sessions.prev_page_url)}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Précédent
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!sessions.next_page_url}
                                    onClick={() => sessions.next_page_url && router.get(sessions.next_page_url)}
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
