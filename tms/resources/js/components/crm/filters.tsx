import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type Option } from '@/lib/crm';
import { router, usePage } from '@inertiajs/react';
import { BookmarkPlus, Columns3, Download, Save, Trash2 } from 'lucide-react';
import { useState } from 'react';

export type Resource = 'organisations' | 'people';

export interface SavedFilterRow {
    id: string;
    resource: Resource;
    name: string;
    filters: Record<string, string | boolean>;
    columns: string[] | null;
    is_shared: boolean;
    mine: boolean;
    author: string | null;
}

export interface ColumnChoice {
    options: Option[];
    defaults: string[];
}

type Criteria = Record<string, string | boolean | null | undefined>;

const ROUTES = {
    organisations: { index: 'crm.organisations.index', export: 'crm.organisations.export' },
    people: { index: 'crm.people.index', export: 'crm.people.export' },
} as const;

const SAVABLE: Record<Resource, string[]> = {
    organisations: ['q', 'status', 'das', 'sector', 'responsible', 'origin', 'mine'],
    people: ['q', 'type', 'status', 'situation', 'funding', 'origin', 'responsible', 'mine'],
};

const NONE = 'none';

/** Criteria worth saving: non-empty savable filters, plus a non-default sort. */
function savableCriteria(resource: Resource, filters: Criteria): Record<string, string | boolean> {
    const out: Record<string, string | boolean> = {};
    SAVABLE[resource].forEach((key) => {
        const value = filters[key];
        if (value === true || (typeof value === 'string' && value !== '')) out[key] = value;
    });
    if (filters.sort && (filters.sort !== 'name' || filters.dir !== 'asc')) {
        out.sort = String(filters.sort);
        out.dir = filters.dir === 'desc' ? 'desc' : 'asc';
    }

    return out;
}

/** Query string values of a criteria set (booleans as 1). */
function toQuery(criteria: Criteria): Record<string, string> {
    const query: Record<string, string> = {};
    Object.entries(criteria).forEach(([key, value]) => {
        if (value === true) query[key] = '1';
        else if (typeof value === 'string' && value !== '') query[key] = value;
    });

    return query;
}

function storedColumns(resource: Resource, valid: string[]): string[] | null {
    try {
        const raw = window.localStorage.getItem(`crm.columns.${resource}`);
        const parsed: unknown = raw ? JSON.parse(raw) : null;
        if (Array.isArray(parsed)) {
            const kept = parsed.filter((c): c is string => typeof c === 'string' && valid.includes(c));

            return kept.length > 0 ? kept : null;
        }
    } catch {
        // Storage unavailable: the defaults apply.
    }

    return null;
}

function rememberColumns(resource: Resource, columns: string[]) {
    try {
        window.localStorage.setItem(`crm.columns.${resource}`, JSON.stringify(columns));
    } catch {
        // Not critical.
    }
}

/**
 * Toolbar of a CRM list: saved filters (own and shared), column choice and
 * Excel export. The list itself stays driven by the page's filters.
 */
export function ListToolbar({
    resource,
    filters,
    savedFilters,
    columns,
    canExport,
}: {
    resource: Resource;
    filters: object;
    savedFilters: SavedFilterRow[];
    columns: ColumnChoice;
    canExport: boolean;
}) {
    const routes = ROUTES[resource];
    const page = usePage<{ errors: Record<string, string> }>();
    const exportError = page.props.errors?.export ?? page.props.errors?.columns;
    const valid = columns.options.map((o) => o.value);

    const [activeId, setActiveId] = useState<string | null>(null);
    const [selected, setSelected] = useState<string[]>(() => storedColumns(resource, valid) ?? columns.defaults);
    const [dialog, setDialog] = useState<'save' | 'columns' | 'delete' | null>(null);

    const active = savedFilters.find((f) => f.id === activeId) ?? null;
    const current = filters as Criteria;
    const criteria = savableCriteria(resource, current);

    const apply = (id: string) => {
        const filter = savedFilters.find((f) => f.id === id);
        if (!filter) {
            setActiveId(null);
            router.get(route(routes.index));

            return;
        }
        setActiveId(id);
        if (filter.columns && filter.columns.length > 0) setSelected(filter.columns);
        router.get(route(routes.index), toQuery(filter.filters));
    };

    const changeColumns = (next: string[]) => {
        setSelected(next);
        rememberColumns(resource, next);
    };

    const exportHref = route(routes.export, { ...toQuery(current), columns: selected });

    return (
        <section aria-label="Filtres enregistrés et export" className="flex flex-wrap items-end gap-2">
            {savedFilters.length > 0 && (
                <div className="grid gap-1">
                    <Label className="text-xs">Filtres enregistrés</Label>
                    <Select value={activeId ?? NONE} onValueChange={(v) => (v === NONE ? apply('') : apply(v))}>
                        <SelectTrigger aria-label="Filtres enregistrés" className="w-56">
                            <SelectValue placeholder="Choisir un filtre" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>Aucun filtre enregistré</SelectItem>
                            {savedFilters.map((f) => (
                                <SelectItem key={f.id} value={f.id}>
                                    {f.name}
                                    {f.is_shared ? (f.mine ? ' (partagé)' : ` (partagé par ${f.author ?? 'un collègue'})`) : ''}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            )}

            <Button type="button" variant="outline" size="sm" onClick={() => setDialog('save')}>
                <BookmarkPlus className="h-4 w-4" />
                Enregistrer ce filtre
            </Button>
            {active?.mine && (
                <>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.put(
                                route('crm.saved-filters.update', active.id),
                                { filters: criteria, columns: selected },
                                { preserveScroll: true, preserveState: true },
                            )
                        }
                    >
                        <Save className="h-4 w-4" />
                        Mettre à jour « {active.name} »
                    </Button>
                    <Button type="button" variant="ghost" size="sm" onClick={() => setDialog('delete')}>
                        <Trash2 className="h-4 w-4" />
                        <span className="sr-only md:not-sr-only">Supprimer</span>
                    </Button>
                </>
            )}

            <Button type="button" variant="outline" size="sm" onClick={() => setDialog('columns')}>
                <Columns3 className="h-4 w-4" />
                Colonnes ({selected.length})
            </Button>

            {/* Export hidden below 768 px: a download is a desktop task. */}
            {canExport && (
                <Button asChild size="sm" className="hidden md:inline-flex">
                    <a href={exportHref} download>
                        <Download className="h-4 w-4" />
                        Exporter (.xlsx)
                    </a>
                </Button>
            )}
            {exportError && (
                <p role="alert" className="text-destructive w-full text-sm">
                    {exportError}
                </p>
            )}

            <SaveDialog
                open={dialog === 'save'}
                onClose={() => setDialog(null)}
                resource={resource}
                criteria={criteria}
                columns={selected}
                onSaved={() => setDialog(null)}
            />
            <ColumnsDialog
                open={dialog === 'columns'}
                onClose={() => setDialog(null)}
                columns={columns}
                selected={selected}
                onChange={changeColumns}
                onReset={() => changeColumns(columns.defaults)}
            />
            <Dialog open={dialog === 'delete'} onOpenChange={(o) => !o && setDialog(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Supprimer ce filtre ?</DialogTitle>
                        <DialogDescription>
                            « {active?.name} » sera supprimé{active?.is_shared ? ' pour tous les utilisateurs de votre organisme' : ''}.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => setDialog(null)}>
                            Annuler
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() =>
                                active &&
                                router.delete(route('crm.saved-filters.destroy', active.id), {
                                    preserveScroll: true,
                                    preserveState: true,
                                    onSuccess: () => {
                                        setActiveId(null);
                                        setDialog(null);
                                    },
                                })
                            }
                        >
                            Supprimer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function SaveDialog({
    open,
    onClose,
    resource,
    criteria,
    columns,
    onSaved,
}: {
    open: boolean;
    onClose: () => void;
    resource: Resource;
    criteria: Record<string, string | boolean>;
    columns: string[];
    onSaved: () => void;
}) {
    const [name, setName] = useState('');
    const [isShared, setIsShared] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(
            route('crm.saved-filters.store'),
            { resource, name, is_shared: isShared, filters: criteria, columns },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (e) => setErrors(e),
                onSuccess: () => {
                    setName('');
                    setIsShared(false);
                    setErrors({});
                    onSaved();
                },
            },
        );
    };

    const summary = Object.keys(criteria).length;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Enregistrer ce filtre</DialogTitle>
                        <DialogDescription>
                            {summary > 0
                                ? `${summary} critère${summary > 1 ? 's' : ''} et ${columns.length} colonnes d'export seront mémorisés.`
                                : `Aucun critère actif : seules les ${columns.length} colonnes d'export seront mémorisées.`}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1">
                        <Label htmlFor="saved-filter-name">Nom du filtre</Label>
                        <Input id="saved-filter-name" value={name} maxLength={100} onChange={(e) => setName(e.target.value)} autoFocus />
                        {(errors.name || errors.filters || errors.columns) && (
                            <p role="alert" className="text-destructive text-sm">
                                {errors.name ?? errors.filters ?? errors.columns}
                            </p>
                        )}
                    </div>
                    <Label className="flex items-center gap-2 font-normal">
                        <Checkbox checked={isShared} onCheckedChange={(c) => setIsShared(c === true)} />
                        Partager avec tous les utilisateurs de l'organisme
                    </Label>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={processing || name.trim() === ''}>
                            Enregistrer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ColumnsDialog({
    open,
    onClose,
    columns,
    selected,
    onChange,
    onReset,
}: {
    open: boolean;
    onClose: () => void;
    columns: ColumnChoice;
    selected: string[];
    onChange: (next: string[]) => void;
    onReset: () => void;
}) {
    const toggle = (value: string, on: boolean) => {
        if (on) onChange([...selected, value]);
        else if (selected.length > 1) onChange(selected.filter((c) => c !== value));
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Colonnes de l'export</DialogTitle>
                    <DialogDescription>Choisissez les colonnes du fichier Excel. Votre choix est mémorisé sur cet appareil.</DialogDescription>
                </DialogHeader>
                <div className="grid max-h-[60vh] gap-2 overflow-y-auto sm:grid-cols-2">
                    {columns.options.map((o) => (
                        <Label key={o.value} className="flex items-center gap-2 font-normal">
                            <Checkbox checked={selected.includes(o.value)} onCheckedChange={(c) => toggle(o.value, c === true)} />
                            {o.label}
                        </Label>
                    ))}
                </div>
                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={onReset}>
                        Colonnes par défaut
                    </Button>
                    <Button type="button" onClick={onClose}>
                        Terminé
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
