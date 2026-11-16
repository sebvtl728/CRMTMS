import type { KeyboardEvent } from 'react';

const normalise = (s: string) => s.trim().toLocaleLowerCase('fr').replace(/\s+/g, ' ');

/**
 * Enter in the search box of a picker: never submits the surrounding form
 * (the typed text is not a choice); picks the result whose label is the
 * text typed, or the only result.
 */
export function pickOnEnter<T>(e: KeyboardEvent<HTMLInputElement>, term: string, hits: T[], label: (h: T) => string, onPick: (h: T) => void): void {
    if (e.key !== 'Enter') {
        return;
    }
    e.preventDefault();
    const match = hits.find((h) => normalise(label(h)) === normalise(term)) ?? (hits.length === 1 ? hits[0] : undefined);
    if (match !== undefined) {
        onPick(match);
    }
}

/**
 * Under the search box of a picker, while nothing is chosen: the text typed
 * is only a search, a result must be clicked.
 */
export function SearchHint({ term, count, searched }: { term: string; count: number; searched: boolean }) {
    if (term.trim().length < 2) {
        return null;
    }
    if (count > 0) {
        return <p className="text-muted-foreground text-xs">Cliquez sur un résultat pour le choisir.</p>;
    }

    return searched ? <p className="text-muted-foreground text-xs">Aucun résultat.</p> : null;
}
