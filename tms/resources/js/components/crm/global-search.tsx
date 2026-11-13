import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Contact, Factory, Loader2, Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

interface Hit {
    id: string;
    name: string;
    subtitle: string | null;
    url: string;
}

interface Results {
    organisations: Hit[];
    people: Hit[];
}

const EMPTY: Results = { organisations: [], people: [] };
const MIN_LENGTH = 2;

/** Dispatch on window to open the palette from anywhere (e.g. the dashboard). */
export const OPEN_EVENT = 'crm:open-search';

/** True when the key press happens while the user is typing somewhere. */
function isTyping(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false;

    return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
}

/**
 * Global search of the CRM (command palette): opens with Ctrl+K / Cmd+K or
 * « / » (outside a field), results grouped Entreprises / Personnes, arrow
 * keys to move, Enter to open the fiche.
 */
export function GlobalSearch() {
    const { auth } = usePage<SharedData>().props;
    const allowed = auth.permissions.includes('crm.view');

    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<Results>(EMPTY);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [throttled, setThrottled] = useState(false);
    const [active, setActive] = useState(0);
    const listRef = useRef<HTMLDivElement>(null);

    // Shortcuts.
    useEffect(() => {
        if (!allowed) return;
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen((o) => !o);
            } else if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !isTyping(e.target)) {
                e.preventDefault();
                setOpen(true);
            }
        };
        // The « Rechercher » button of the dashboard asks for the palette.
        const onRequest = () => setOpen(true);
        window.addEventListener('keydown', onKey);
        window.addEventListener(OPEN_EVENT, onRequest);

        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener(OPEN_EVENT, onRequest);
        };
    }, [allowed]);

    // Debounced lookup (250 ms), the previous request is cancelled.
    useEffect(() => {
        const trimmed = term.trim();
        if (!open || trimmed.length < MIN_LENGTH) {
            setResults(EMPTY);
            setLoading(false);
            setFailed(false);
            setThrottled(false);

            return;
        }
        setLoading(true);
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(route('crm.search', { q: trimmed }), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((r) => (r.ok ? (r.json() as Promise<Results>) : Promise.reject(new Error(String(r.status)))))
                .then((json) => {
                    setResults(json);
                    setFailed(false);
                    setThrottled(false);
                    setActive(0);
                    setLoading(false);
                })
                .catch((error: unknown) => {
                    if (error instanceof DOMException && error.name === 'AbortError') return;
                    setFailed(true);
                    setThrottled(error instanceof Error && error.message === '429');
                    setLoading(false);
                });
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, open]);

    const flat = useMemo(
        () => [
            ...results.organisations.map((hit) => ({ hit, group: 'organisations' as const })),
            ...results.people.map((hit) => ({ hit, group: 'people' as const })),
        ],
        [results],
    );

    const close = useCallback(() => {
        setOpen(false);
        setTerm('');
        setResults(EMPTY);
    }, []);

    const go = (url: string) => {
        close();
        router.visit(url);
    };

    const onInputKey = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((a) => (flat.length === 0 ? 0 : (a + 1) % flat.length));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((a) => (flat.length === 0 ? 0 : (a - 1 + flat.length) % flat.length));
        } else if (e.key === 'Enter' && flat[active]) {
            e.preventDefault();
            go(flat[active].hit.url);
        }
    };

    useEffect(() => {
        listRef.current?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
    }, [active, results]);

    if (!allowed) return null;

    const trimmed = term.trim();
    const option = (item: { hit: Hit; group: 'organisations' | 'people' }, index: number) => {
        const Icon = item.group === 'organisations' ? Factory : Contact;

        return (
            <div
                key={`${item.group}-${item.hit.id}`}
                id={`global-search-option-${index}`}
                role="option"
                aria-selected={index === active}
                onMouseEnter={() => setActive(index)}
                onClick={() => go(item.hit.url)}
                className={cn(
                    'flex cursor-pointer items-center gap-3 rounded-md px-3 py-2 text-sm',
                    index === active && 'bg-accent text-accent-foreground',
                )}
            >
                <Icon className="text-muted-foreground h-4 w-4 shrink-0" aria-hidden />
                <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium">{item.hit.name}</span>
                    {item.hit.subtitle && <span className="text-muted-foreground block truncate text-xs">{item.hit.subtitle}</span>}
                </span>
            </div>
        );
    };

    return (
        <>
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="text-muted-foreground hidden h-9 w-56 justify-between font-normal md:inline-flex"
                onClick={() => setOpen(true)}
                aria-label="Rechercher une entreprise ou une personne"
            >
                <span className="flex items-center gap-2">
                    <Search className="h-4 w-4" />
                    Rechercher…
                </span>
                <kbd className="bg-muted rounded border px-1.5 text-xs">Ctrl K</kbd>
            </Button>
            <Button type="button" variant="ghost" size="icon" className="md:hidden" onClick={() => setOpen(true)} aria-label="Rechercher">
                <Search className="h-5 w-5" />
            </Button>

            <Dialog open={open} onOpenChange={(o) => (o ? setOpen(true) : close())}>
                <DialogContent className="top-[20%] max-w-xl translate-y-0 gap-0 p-0 sm:rounded-xl">
                    <DialogTitle className="sr-only">Recherche</DialogTitle>
                    <DialogDescription className="sr-only">
                        Tapez au moins deux caractères : un nom, une ville, un SIRET, un e-mail ou un téléphone. Flèches pour choisir, Entrée pour
                        ouvrir.
                    </DialogDescription>
                    <div className="flex items-center gap-2 border-b px-4">
                        <Search className="text-muted-foreground h-4 w-4 shrink-0" aria-hidden />
                        <input
                            autoFocus
                            role="combobox"
                            aria-expanded={flat.length > 0}
                            aria-controls="global-search-list"
                            aria-activedescendant={flat.length > 0 ? `global-search-option-${active}` : undefined}
                            aria-label="Rechercher une entreprise ou une personne"
                            value={term}
                            onChange={(e) => setTerm(e.target.value)}
                            onKeyDown={onInputKey}
                            maxLength={100}
                            placeholder="Entreprise, personne, SIRET, e-mail, téléphone…"
                            className="placeholder:text-muted-foreground h-12 w-full bg-transparent pr-8 text-sm outline-none"
                        />
                        {loading && <Loader2 className="text-muted-foreground h-4 w-4 shrink-0 animate-spin" aria-label="Recherche en cours" />}
                    </div>
                    <div ref={listRef} id="global-search-list" role="listbox" aria-label="Résultats" className="max-h-[50vh] overflow-y-auto p-2">
                        {trimmed.length < MIN_LENGTH && (
                            <p className="text-muted-foreground px-3 py-6 text-center text-sm">Tapez au moins {MIN_LENGTH} caractères.</p>
                        )}
                        {failed && (
                            <p role="alert" className="text-destructive px-3 py-6 text-center text-sm">
                                {throttled ? 'Trop de requêtes, réessayez dans quelques instants.' : 'La recherche a échoué, réessayez.'}
                            </p>
                        )}
                        {trimmed.length >= MIN_LENGTH && !loading && !failed && flat.length === 0 && (
                            <p className="text-muted-foreground px-3 py-6 text-center text-sm">Aucun résultat pour « {trimmed} ».</p>
                        )}
                        {results.organisations.length > 0 && (
                            <div role="group" aria-label="Entreprises">
                                <p className="text-muted-foreground px-3 py-1 text-xs font-medium">Entreprises</p>
                                {results.organisations.map((hit, i) => option({ hit, group: 'organisations' }, i))}
                            </div>
                        )}
                        {results.people.length > 0 && (
                            <div role="group" aria-label="Personnes">
                                <p className="text-muted-foreground px-3 py-1 text-xs font-medium">Personnes</p>
                                {results.people.map((hit, i) => option({ hit, group: 'people' }, results.organisations.length + i))}
                            </div>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
