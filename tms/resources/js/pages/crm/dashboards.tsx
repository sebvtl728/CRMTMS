import { IndicatorCard, type IndicatorCardData } from '@/components/crm/indicator-card';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Filters {
    tab: 'week' | 'month';
    preset: string;
    from: string;
    to: string;
    das: string | null;
    target: string | null;
    responsible: string | null;
}

interface DashboardsProps {
    filters: Filters;
    period: { current: string; previous: string };
    cards: IndicatorCardData[];
    options: { das: { value: string; label: string }[]; responsibles: { value: string; label: string }[] };
    can: { export: boolean };
}

const PRESETS: Record<string, string> = {
    this_week: 'Cette semaine',
    last_week: 'Semaine dernière',
    this_month: 'Ce mois',
    last_month: 'Mois dernier',
    this_quarter: 'Ce trimestre',
    this_year: 'Cette année',
    custom: 'Dates libres',
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tableaux de bord', href: '/crm/dashboards' }];

const query = (f: Filters) =>
    Object.fromEntries(
        Object.entries({ ...f, from: f.preset === 'custom' ? f.from : null, to: f.preset === 'custom' ? f.to : null }).filter(
            ([, v]) => v !== null && v !== '',
        ),
    ) as Record<string, string>;

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export default function Dashboards({ filters, period, cards, options, can }: DashboardsProps) {
    const [form, setForm] = useState<Filters>(filters);
    const set = (key: keyof Filters, value: string) => setForm((f) => ({ ...f, [key]: value === '' ? null : value }));
    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get('/crm/dashboards', query(form), { preserveState: true, preserveScroll: true });
    };
    const tabUrl = (tab: Filters['tab']) => `/crm/dashboards?${new URLSearchParams({ tab })}`;
    const exportUrl = (format: 'csv' | 'pdf') => `/crm/dashboards/export.${format}?${new URLSearchParams(query(filters))}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tableaux de bord" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading title="Tableaux de bord" description={`Période ${period.current}, comparée à la période ${period.previous}.`} />
                    {can.export && (
                        <div className="flex gap-2">
                            <Button asChild variant="outline" size="sm">
                                <a href={exportUrl('csv')}>
                                    <Download className="h-4 w-4" />
                                    Excel (CSV)
                                </a>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <a href={exportUrl('pdf')} target="_blank" rel="noopener">
                                    <Download className="h-4 w-4" />
                                    PDF
                                </a>
                            </Button>
                        </div>
                    )}
                </div>

                <nav className="flex gap-2" aria-label="Vues">
                    {(['week', 'month'] as const).map((tab) => (
                        <Button key={tab} asChild size="sm" variant={filters.tab === tab ? 'default' : 'outline'}>
                            <Link href={tabUrl(tab)}>{tab === 'week' ? 'Semaine' : 'Mois / direction'}</Link>
                        </Button>
                    ))}
                </nav>

                <form onSubmit={apply} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="preset">Période</Label>
                        <select id="preset" className={selectClass} value={form.preset} onChange={(e) => set('preset', e.target.value)}>
                            {Object.entries(PRESETS).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                    {form.preset === 'custom' && (
                        <>
                            <div className="grid gap-1">
                                <Label htmlFor="from">Du</Label>
                                <Input id="from" type="date" value={form.from} onChange={(e) => set('from', e.target.value)} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="to">Au</Label>
                                <Input id="to" type="date" value={form.to} onChange={(e) => set('to', e.target.value)} />
                            </div>
                        </>
                    )}
                    <div className="grid gap-1">
                        <Label htmlFor="das">DAS</Label>
                        <select id="das" className={selectClass} value={form.das ?? ''} onChange={(e) => set('das', e.target.value)}>
                            <option value="">Tous</option>
                            {options.das.map((d) => (
                                <option key={d.value} value={d.value}>
                                    {d.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="target">Cible</Label>
                        <select id="target" className={selectClass} value={form.target ?? ''} onChange={(e) => set('target', e.target.value)}>
                            <option value="">Toutes</option>
                            <option value="organisation">Entreprises</option>
                            <option value="person">Particuliers</option>
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="responsible">Commercial</Label>
                        <select
                            id="responsible"
                            className={selectClass}
                            value={form.responsible ?? ''}
                            onChange={(e) => set('responsible', e.target.value)}
                        >
                            <option value="">Tous</option>
                            {options.responsibles.map((u) => (
                                <option key={u.value} value={u.value}>
                                    {u.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button type="submit" size="sm">
                        Appliquer
                    </Button>
                </form>

                {cards.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Aucun indicateur à afficher avec vos droits.</p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {cards.map((card) => (
                            <IndicatorCard key={card.key} card={card} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
