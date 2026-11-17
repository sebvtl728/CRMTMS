import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type MouseEvent } from 'react';

/** Steps of the session page (spec § 4.1), in their order. */
export const STEP_LABELS: Record<string, string> = {
    synthese: 'Synthèse',
    configuration: 'Configuration',
    planning: 'Planning',
    stagiaires: 'Stagiaires',
    suivi: 'Suivi',
};

export const DEFAULT_STEP = 'synthese';

/** The former tabs of the page (`?tab=`), still found in links. */
const LEGACY_TABS: Record<string, string> = {
    session: 'configuration',
    planning: 'planning',
    présences: 'suivi',
    presences: 'suivi',
};

/** The step asked by the URL (`etape`, or the former `tab`), the default one when unknown or not visible. */
export function stepFromSearch(search: string, steps: string[]): string {
    const params = new URLSearchParams(search);
    const tab = params.get('tab');
    const asked = params.get('etape') ?? (tab === null ? null : (LEGACY_TABS[tab.toLowerCase()] ?? null));

    return asked !== null && steps.includes(asked) ? asked : DEFAULT_STEP;
}

function stepUrl(step: string): string {
    const url = new URL(window.location.href);
    url.searchParams.delete('tab');
    url.searchParams.set('etape', step);
    url.hash = '';

    return url.pathname + url.search;
}

/**
 * Writes the step in the URL by replacing the current history entry, through
 * Inertia (a client-side visit, no server round trip) so that its own later
 * history writes (remembered form state, scroll positions) keep it.
 */
function writeStep(step: string) {
    router.replace({ url: stepUrl(step), preserveState: true, preserveScroll: true });
}

/** Scrolls to an element of the step once it is shown (it may load later, as the attendance does). */
function scrollToAnchor(anchor: string) {
    let tries = 0;
    const attempt = () => {
        const element = document.getElementById(anchor);
        if (element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else if (tries++ < 30) {
            window.setTimeout(attempt, 100);
        }
    };
    window.setTimeout(attempt, 0);
}

/**
 * The step of the session page when a visit that changes data (POST, PUT…)
 * leaves it: its redirect back to the session (without `etape`) may remount
 * the page before the `navigate` listener below runs; the new page then
 * starts on that step. Dropped once the user is on another page.
 */
let carried: { path: string; step: string } | null = null;

if (typeof document !== 'undefined') {
    document.addEventListener('inertia:navigate', () => {
        if (carried !== null && window.location.pathname !== carried.path) {
            carried = null;
        }
    });
}

function initialStep(steps: string[]): string {
    if (typeof window === 'undefined') {
        return DEFAULT_STEP;
    }
    const params = new URLSearchParams(window.location.search);
    if (params.has('etape') || params.has('tab') || carried === null || carried.path !== window.location.pathname) {
        return stepFromSearch(window.location.search, steps);
    }
    const step = carried.step;
    carried = null;

    return steps.includes(step) ? step : DEFAULT_STEP;
}

/**
 * The current step of the session page: read in the URL on load, written back
 * by replacing the history entry (writeStep; every datum of the page stays
 * loaded). `setStep(step, anchor)` also scrolls to the anchor.
 * A redirect back to the session after an action keeps the step.
 */
export function useStep(steps: string[]): [string, (step: string, anchor?: string | null) => void] {
    const [step, setCurrent] = useState(() => initialStep(steps));
    const current = useRef(step);
    current.current = step;

    useEffect(() => {
        const path = window.location.pathname;
        // Back on the page with `etape` lost (the step was carried): put it back.
        const keepInUrl = () => {
            const params = new URLSearchParams(window.location.search);
            if (window.location.pathname === path && current.current !== DEFAULT_STEP && params.get('etape') !== current.current) {
                writeStep(current.current);
            }
        };
        // After the first page load of the router (a write before it would be cancelled).
        const timer = window.setTimeout(keepInUrl, 0);
        const offStart = router.on('start', (event) => {
            if (event.detail.visit.method !== 'get' && window.location.pathname === path) {
                carried = { path, step: current.current };
            }
        });
        // A visit that stays on the page (a redirect to the session, state kept) drops `etape`: put it back.
        const offNavigate = router.on('navigate', () => {
            keepInUrl();
            if (window.location.pathname === path) {
                carried = null;
            }
        });

        return () => {
            window.clearTimeout(timer);
            offStart();
            offNavigate();
        };
    }, []);

    const setStep = useCallback(
        (next: string, anchor?: string | null) => {
            const target = steps.includes(next) ? next : DEFAULT_STEP;
            setCurrent(target);
            writeStep(target);
            if (anchor) {
                scrollToAnchor(anchor);
            }
        },
        [steps],
    );

    return [step, setStep];
}

/** The numbered steps of the session page, scrolled horizontally on a phone. */
export function StepNav({ steps, current, onChange }: { steps: string[]; current: string; onChange: (step: string) => void }) {
    const open = (e: MouseEvent<HTMLAnchorElement>, step: string) => {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();
        onChange(step);
    };

    return (
        <nav aria-label="Étapes de la session" className="-mx-4 overflow-x-auto border-b px-4">
            <ol className="flex min-w-max gap-1">
                {steps.map((s, i) => {
                    const active = s === current;

                    return (
                        <li key={s}>
                            <a
                                href={`?etape=${s}`}
                                aria-current={active ? 'step' : undefined}
                                onClick={(e) => open(e, s)}
                                className={`-mb-px flex items-center gap-2 border-b-2 px-4 py-2 text-sm whitespace-nowrap ${active ? 'border-primary font-medium' : 'text-muted-foreground hover:text-foreground border-transparent'}`}
                            >
                                <span
                                    aria-hidden="true"
                                    className={`flex h-5 w-5 items-center justify-center rounded-full text-xs ${active ? 'bg-primary text-primary-foreground' : 'bg-muted'}`}
                                >
                                    {i + 1}
                                </span>
                                {STEP_LABELS[s] ?? s}
                            </a>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
