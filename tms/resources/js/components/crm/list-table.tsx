import { Badge } from '@/components/ui/badge';
import { type Option } from '@/lib/crm';
import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';

export interface TableRow {
    id: string;
    deleted: boolean;
    cells: Record<string, string | null>;
    /** Extra badges next to the title (e.g. « Opposé à la prospection »). */
    badges?: string[];
}

/**
 * Desktop table of a CRM list showing the chosen columns (the same keys as the
 * export). Cards are used below 768 px by the pages, they stay fixed.
 */
export function ListTable({
    rows,
    options,
    selected,
    titleKeys,
    href,
    sortKeys,
    sort,
    dir,
    onSort,
}: {
    rows: TableRow[];
    options: Option[];
    selected: string[];
    /** Columns holding the name, linked to the fiche (the first selected column when none is chosen). */
    titleKeys: string[];
    href: (id: string) => string;
    /** Column key -> sort key understood by the server. */
    sortKeys: Record<string, string>;
    sort: string;
    dir: 'asc' | 'desc';
    onSort: (sortKey: string) => void;
}) {
    const labels = Object.fromEntries(options.map((o) => [o.value, o.label]));
    const columns = selected.filter((c) => c in labels);
    const linked = columns.find((c) => titleKeys.includes(c)) ?? columns[0];

    return (
        <div className="hidden overflow-x-auto rounded-xl border md:block">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        {columns.map((c) => {
                            const sortKey = sortKeys[c];
                            const current = sortKey !== undefined && sort === sortKey;

                            return (
                                <th
                                    key={c}
                                    className="px-4 py-2 font-medium"
                                    aria-sort={current ? (dir === 'asc' ? 'ascending' : 'descending') : undefined}
                                >
                                    {sortKey ? (
                                        <button
                                            type="button"
                                            className="inline-flex items-center gap-1 hover:underline"
                                            onClick={() => onSort(sortKey)}
                                        >
                                            {labels[c]}
                                            {current && (dir === 'asc' ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />)}
                                        </button>
                                    ) : (
                                        labels[c]
                                    )}
                                </th>
                            );
                        })}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className="hover:bg-muted/30 border-t">
                            {columns.map((c) => (
                                <td key={c} className={c === linked ? 'px-4 py-2 font-medium' : 'px-4 py-2'}>
                                    {c === linked ? (
                                        <>
                                            <Link href={href(row.id)} className="hover:underline">
                                                {row.cells[c] || '-'}
                                            </Link>
                                            {row.deleted && (
                                                <Badge variant="destructive" className="ml-2">
                                                    Supprimée
                                                </Badge>
                                            )}
                                            {row.badges?.map((b) => (
                                                <Badge key={b} variant="outline" className="ml-2">
                                                    {b}
                                                </Badge>
                                            ))}
                                        </>
                                    ) : (
                                        (row.cells[c] ?? '-')
                                    )}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
