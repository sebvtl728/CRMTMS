import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CheckCircle2, TriangleAlert, X } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * The message of the last action (« Réglages enregistrés. », a refusal…),
 * pinned at the bottom of the screen so it is seen wherever the button was;
 * a success fades after 6 seconds, an error stays until closed.
 */
export function FlashBanner() {
    const { flash } = usePage<SharedData>().props;
    const [shown, setShown] = useState<{ kind: 'status' | 'error'; text: string } | null>(null);

    useEffect(() => {
        if (flash?.error) setShown({ kind: 'error', text: flash.error });
        else if (flash?.status) setShown({ kind: 'status', text: flash.status });
    }, [flash?.error, flash?.status]);

    useEffect(() => {
        if (shown?.kind !== 'status') return;
        const timer = window.setTimeout(() => setShown(null), 6000);
        return () => window.clearTimeout(timer);
    }, [shown]);

    if (shown === null) return null;
    const error = shown.kind === 'error';

    return (
        <div
            role={error ? 'alert' : 'status'}
            className={`fixed right-4 bottom-4 z-50 flex max-w-md items-start gap-2 rounded-md border p-3 text-sm shadow-lg ${
                error
                    ? 'border-red-300 bg-red-50 text-red-900 dark:bg-red-950 dark:text-red-100'
                    : 'border-green-300 bg-green-50 text-green-900 dark:bg-green-950 dark:text-green-100'
            }`}
        >
            {error ? <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" /> : <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />}
            <span className="flex-1">{shown.text}</span>
            <button type="button" aria-label="Fermer le message" className="opacity-70 hover:opacity-100" onClick={() => setShown(null)}>
                <X className="h-4 w-4" />
            </button>
        </div>
    );
}
