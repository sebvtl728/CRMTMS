/** The colour of a session (the one its séances wear in the planning), as a small dot. */
export function SessionColorDot({ color, className = '' }: { color?: string | null; className?: string }) {
    if (!color) return null;

    return <span aria-hidden="true" className={`inline-block h-2.5 w-2.5 shrink-0 rounded-full ${className}`} style={{ backgroundColor: color }} />;
}
