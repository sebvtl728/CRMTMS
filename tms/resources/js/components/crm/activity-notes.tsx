import { cn } from '@/lib/utils';
import { useState } from 'react';

/**
 * « Motif » and « Suite à donner » of an action under its subject: one line
 * each (full text in the tooltip), « Voir tout » shows them whole.
 */
export function ActivityNotes({ purpose, nextSteps, className }: { purpose?: string | null; nextSteps?: string | null; className?: string }) {
    const [open, setOpen] = useState(false);
    const notes = [
        { key: 'purpose', label: 'Motif', text: purpose },
        { key: 'next_steps', label: 'Suite à donner', text: nextSteps },
    ].filter((n): n is { key: string; label: string; text: string } => typeof n.text === 'string' && n.text.trim() !== '');
    if (notes.length === 0) return null;
    const long = notes.some((n) => n.text.length > 80 || n.text.includes('\n'));

    return (
        <div className={cn('mt-1 grid gap-0.5 text-sm', className)}>
            {notes.map((n) => (
                <p key={n.key} className={cn('break-words', open ? 'whitespace-pre-line' : 'truncate')} title={open ? undefined : n.text}>
                    <span className="text-muted-foreground">{n.label} : </span>
                    {n.text}
                </p>
            ))}
            {long && (
                <button
                    type="button"
                    className="text-muted-foreground justify-self-start text-xs underline-offset-2 hover:underline"
                    aria-expanded={open}
                    onClick={() => setOpen((o) => !o)}
                >
                    {open ? 'Réduire' : 'Voir tout'}
                </button>
            )}
        </div>
    );
}
