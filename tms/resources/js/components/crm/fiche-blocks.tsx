import { Badge } from '@/components/ui/badge';
import { Link } from '@inertiajs/react';

/**
 * A block another module adds to a fiche (Crm\Public\FicheBlocks):
 * « Formations suivies » of a person, « Dossiers » of an entreprise…
 * Shown as a tab of the fiche, all the same way.
 */
export interface FicheBlock {
    key: string;
    title: string;
    empty: string;
    rows: {
        id: string;
        title: string;
        subtitle?: string | null;
        meta?: string | null;
        badge?: string | null;
        href?: string | null;
        /** Detail lines (the planning of a trainee: next séances, placements). */
        details?: string[];
    }[];
}

export function FicheBlockPanel({ block }: { block: FicheBlock }) {
    return (
        <div role="tabpanel" id={`panel-${block.title}`} aria-labelledby={`tab-${block.title}`} className="grid gap-3">
            {block.rows.length === 0 ? (
                <div className="text-muted-foreground rounded-xl border p-6 text-center text-sm">{block.empty}</div>
            ) : (
                <ul className="grid gap-3">
                    {block.rows.map((row) => {
                        const body = (
                            <>
                                <div className="flex items-start justify-between gap-2">
                                    <span className="font-medium">{row.title}</span>
                                    {row.badge && <Badge variant="secondary">{row.badge}</Badge>}
                                </div>
                                {row.subtitle && <p className="text-muted-foreground mt-1 text-sm">{row.subtitle}</p>}
                                {row.meta && <p className="mt-1 text-sm">{row.meta}</p>}
                                {row.details && row.details.length > 0 && (
                                    <ul className="text-muted-foreground mt-2 grid gap-0.5 border-t pt-2 text-xs">
                                        {row.details.map((line, i) => (
                                            <li key={i}>{line}</li>
                                        ))}
                                    </ul>
                                )}
                            </>
                        );

                        return (
                            <li key={row.id}>
                                {row.href ? (
                                    <Link href={row.href} className="hover:bg-muted/30 block rounded-xl border p-4">
                                        {body}
                                    </Link>
                                ) : (
                                    <div className="rounded-xl border p-4">{body}</div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
