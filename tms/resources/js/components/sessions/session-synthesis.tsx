import { AlertTriangle, Check, CircleAlert, Minus } from 'lucide-react';
import { type MouseEvent, type ReactNode } from 'react';

/** A progress card of the « Synthèse » (server side: Sessions\Summary\Card). */
export interface CardRow {
    key: string;
    title: string;
    value: string;
    state: 'done' | 'todo' | 'alert' | 'na';
    text: string;
    step: string;
    anchor: string | null;
}

const STATES: Record<CardRow['state'], { label: string; icon: ReactNode; className: string }> = {
    done: { label: 'Fait', icon: <Check className="h-4 w-4" />, className: 'bg-green-600/10 text-green-700 dark:text-green-400' },
    todo: { label: 'À faire', icon: <CircleAlert className="h-4 w-4" />, className: 'bg-amber-500/10 text-amber-700 dark:text-amber-400' },
    alert: { label: 'Alerte', icon: <AlertTriangle className="h-4 w-4" />, className: 'bg-destructive/10 text-destructive' },
    na: { label: 'Sans objet', icon: <Minus className="h-4 w-4" />, className: 'bg-muted text-muted-foreground' },
};

function ProgressCard({ card, onOpen }: { card: CardRow; onOpen: (step: string, anchor: string | null) => void }) {
    const state = STATES[card.state];
    const open = (e: MouseEvent<HTMLAnchorElement>) => {
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();
        onOpen(card.step, card.anchor);
    };

    return (
        <li className="flex flex-col gap-2 rounded-xl border p-4" data-state={card.state} aria-label={card.title}>
            <div className="flex items-start justify-between gap-2">
                <h3 className="text-sm font-medium">{card.title}</h3>
                <span className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs ${state.className}`}>
                    <span aria-hidden="true">{state.icon}</span>
                    {state.label}
                </span>
            </div>
            <p className="text-2xl font-semibold tabular-nums">{card.value}</p>
            <p className="text-muted-foreground text-sm">{card.text}</p>
            <a
                href={`?etape=${card.step}${card.anchor ? `#${card.anchor}` : ''}`}
                onClick={open}
                className="text-primary mt-auto self-start text-sm font-medium hover:underline"
            >
                Ouvrir<span className="sr-only"> : {card.title}</span>
            </a>
        </li>
    );
}

/**
 * The « Synthèse » step: the progress cards of the session and, beside them
 * (under them on a phone), the `aside` panel. A cancelled session shows its
 * banner instead of the cards.
 */
export function SessionSynthesis({
    cards,
    cancelled,
    aside,
    onOpen,
}: {
    cards: CardRow[];
    /** The reason of a cancelled session (null when not cancelled). */
    cancelled: { reason: string | null } | null;
    aside?: ReactNode;
    onOpen: (step: string, anchor: string | null) => void;
}) {
    return (
        <div className={aside ? 'grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]' : 'grid gap-6'}>
            <section aria-label="Avancement" className="grid content-start gap-3">
                {cancelled ? (
                    <div className="border-destructive/40 bg-destructive/5 rounded-xl border p-3 text-sm">
                        Session annulée : {cancelled.reason ?? '-'}
                    </div>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {cards.map((card) => (
                            <ProgressCard key={card.key} card={card} onOpen={onOpen} />
                        ))}
                    </ul>
                )}
            </section>
            {aside && <aside className="grid content-start gap-3">{aside}</aside>}
        </div>
    );
}
