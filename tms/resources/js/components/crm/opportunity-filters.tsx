import { FilterSelect } from '@/components/crm/filter-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Option } from '@/lib/crm';
import { router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export interface OpportunityFilterValues {
    q: string;
    das: string | null;
    training: string | null;
    target: string | null;
    responsible: string | null;
    priority: string | null;
    stage: string | null;
    close_from: string | null;
    close_to: string | null;
    mine: boolean;
    sort: string;
    dir: 'asc' | 'desc';
}

export interface OpportunityFilterOptions {
    target: Option[];
    das: Option[];
    training: Option[];
    stage: Option[];
    priority: Option[];
    responsible: Option[];
    sort: Option[];
}

/** Query string of the filters (list, kanban and export read the same keys). */
export function opportunityQuery(filters: OpportunityFilterValues): Record<string, string> {
    const query: Record<string, string> = {};
    if (filters.q) query.q = filters.q;
    (['das', 'training', 'target', 'responsible', 'priority', 'stage', 'close_from', 'close_to'] as const).forEach((k) => {
        if (filters[k]) query[k] = String(filters[k]);
    });
    if (filters.mine) query.mine = '1';
    if (filters.sort !== 'expected_close_on' || filters.dir !== 'asc') {
        query.sort = filters.sort;
        query.dir = filters.dir;
    }

    return query;
}

export function isFiltered(filters: OpportunityFilterValues): boolean {
    return Boolean(
        filters.q ||
            filters.das ||
            filters.training ||
            filters.target ||
            filters.responsible ||
            filters.priority ||
            filters.stage ||
            filters.close_from ||
            filters.close_to ||
            filters.mine,
    );
}

/**
 * Filter bar of the opportunities (list and kanban): every change reloads
 * the page given by `routeName` with the new query string.
 */
export function OpportunityFilters({
    routeName,
    filters,
    options,
    showStage = true,
}: {
    routeName: string;
    filters: OpportunityFilterValues;
    options: OpportunityFilterOptions;
    showStage?: boolean;
}) {
    const [search, setSearch] = useState(filters.q);
    const first = useRef(true);

    const visit = (changes: Partial<OpportunityFilterValues>) =>
        router.get(route(routeName), opportunityQuery({ ...filters, ...changes }), { preserveState: true, replace: true });

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = setTimeout(() => visit({ q: search }), 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    return (
        <section aria-label="Filtres" className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2 lg:grid-cols-4">
            <div className="grid gap-1 sm:col-span-2 lg:col-span-4">
                <Label htmlFor="q" className="text-xs">
                    Recherche (objet, entreprise, personne)
                </Label>
                <Input id="q" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Ex. HACCP, bistrot…" />
            </div>
            <FilterSelect label="Cible" value={filters.target} options={options.target} onChange={(v) => visit({ target: v })} />
            <FilterSelect label="DAS" value={filters.das} options={options.das} onChange={(v) => visit({ das: v })} />
            {options.training.length > 0 && (
                <FilterSelect label="Formation" value={filters.training} options={options.training} onChange={(v) => visit({ training: v })} />
            )}
            {showStage && <FilterSelect label="Étape" value={filters.stage} options={options.stage} onChange={(v) => visit({ stage: v })} />}
            <FilterSelect label="Priorité" value={filters.priority} options={options.priority} onChange={(v) => visit({ priority: v })} />
            <FilterSelect label="Responsable" value={filters.responsible} options={options.responsible} onChange={(v) => visit({ responsible: v })} />
            <div className="grid gap-1">
                <Label htmlFor="close_from" className="text-xs">
                    Clôture prévue à partir du
                </Label>
                <Input id="close_from" type="date" value={filters.close_from ?? ''} onChange={(e) => visit({ close_from: e.target.value || null })} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="close_to" className="text-xs">
                    Clôture prévue jusqu'au
                </Label>
                <Input id="close_to" type="date" value={filters.close_to ?? ''} onChange={(e) => visit({ close_to: e.target.value || null })} />
            </div>
            <div className="flex items-end gap-4">
                <Label className="flex items-center gap-2 text-sm font-normal">
                    <Checkbox checked={filters.mine} onCheckedChange={(c) => visit({ mine: c === true })} />
                    Mes opportunités
                </Label>
                {isFiltered(filters) && (
                    <Button type="button" variant="ghost" size="sm" onClick={() => router.get(route(routeName))}>
                        Réinitialiser
                    </Button>
                )}
            </div>
        </section>
    );
}

/**
 * Excel export of the filtered opportunities (default columns). A JSON
 * pre-flight tells first when the export is refused (too many rows,
 * throttle): the download itself is a plain navigation.
 */
export function OpportunityExportButton({ filters }: { filters: OpportunityFilterValues }) {
    const [checking, setChecking] = useState(false);
    const [refusal, setRefusal] = useState<string | null>(null);

    const download = async () => {
        setRefusal(null);
        setChecking(true);
        try {
            const response = await fetch(route('crm.opportunities.export.check', opportunityQuery(filters)), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const json = (await response.json().catch(() => ({}))) as { allowed?: boolean; message?: string | null };
            if (!response.ok || json.allowed === false) {
                setRefusal(json.message ?? 'Export impossible pour le moment.');

                return;
            }
            window.location.assign(route('crm.opportunities.export', opportunityQuery(filters)));
        } catch {
            setRefusal("L'export a échoué, réessayez.");
        } finally {
            setChecking(false);
        }
    };

    return (
        <div className="hidden md:block">
            <Button type="button" variant="outline" onClick={download} disabled={checking}>
                <Download className="h-4 w-4" />
                Exporter (.xlsx)
            </Button>
            {refusal && (
                <p role="alert" className="text-destructive mt-1 text-sm">
                    {refusal}
                </p>
            )}
        </div>
    );
}
