import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface TodoRow {
    key: string;
    group: string;
    label: string;
    /** Null: the source could not be read. */
    count: number | null;
    url: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'À traiter', href: '/a-traiter' }];

/** « À traiter »: what each module has waiting for the user, within his rights; each line leads to its screen. */
export default function Todo({ rows, groups }: { rows: TodoRow[]; groups: string[] }) {
    const open = rows.filter((r) => r.count === null || r.count > 0);
    const done = rows.filter((r) => r.count === 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="À traiter" />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="À traiter"
                    description="Ce qui vous attend dans chaque domaine, selon vos droits. Chaque ligne mène à l'écran concerné."
                />
                {open.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Rien à traiter pour le moment.</p>
                ) : (
                    groups
                        .filter((g) => open.some((r) => r.group === g))
                        .map((g) => (
                            <section key={g} className="grid gap-2">
                                <h3 className="font-medium">{g}</h3>
                                <ul className="divide-y rounded-md border">
                                    {open
                                        .filter((r) => r.group === g)
                                        .map((r) => (
                                            <li key={r.key} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
                                                <span className="flex items-center gap-2">
                                                    {r.count === null ? (
                                                        <Badge variant="outline">Indisponible</Badge>
                                                    ) : (
                                                        <Badge variant="destructive">{r.count}</Badge>
                                                    )}
                                                    {r.label}
                                                </span>
                                                <Button asChild variant="outline" size="sm">
                                                    <Link href={r.url}>Voir</Link>
                                                </Button>
                                            </li>
                                        ))}
                                </ul>
                            </section>
                        ))
                )}
                {done.length > 0 && (
                    <details className="text-sm">
                        <summary className="text-muted-foreground cursor-pointer">Rien à traiter ({done.length})</summary>
                        <ul className="text-muted-foreground mt-2 grid gap-1 pl-4">
                            {done.map((r) => (
                                <li key={r.key}>
                                    {r.group} · {r.label}
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </div>
        </AppLayout>
    );
}
