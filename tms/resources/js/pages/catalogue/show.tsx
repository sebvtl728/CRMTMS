import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatSize } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

interface SessionsBlockData {
    indicators: { upcoming: number; running: number; ended: number; trainees: number };
    sessions: {
        id: string;
        number: string;
        label: string;
        starts_on: string;
        ends_on: string;
        kind_label: string;
        status: string;
        status_label: string;
        href: string;
    }[];
    can_create: boolean;
    create_href: string;
}

interface Block {
    key: string;
    title: string;
    data: Record<string, unknown>;
}

interface ShowProps {
    training: {
        id: string;
        code: string | null;
        title: string;
        das: string | null;
        duration_hours: string | null;
        duration_days: string | null;
        price_company: string | null;
        price_individual: string | null;
        funding_modes: string[];
        is_active: boolean;
        modalities: string[];
    };
    brochures: { id: string; title: string; original_name: string; size: number; source: 'training' | 'das' }[];
    blocks: Block[];
    can: { manage: boolean; brochures: boolean };
    status?: string | null;
}

const euros = (value: string | null) => (value ? `${value} €` : '—');

function Tile({ label, value }: { label: string; value: number | string }) {
    return (
        <div className="rounded-xl border p-3">
            <div className="text-2xl font-semibold">{value}</div>
            <div className="text-muted-foreground text-xs">{label}</div>
        </div>
    );
}

function SessionsBlock({ data }: { data: SessionsBlockData }) {
    return (
        <section aria-label="Sessions" className="grid gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">Sessions</h2>
                {data.can_create && (
                    <Button asChild size="sm">
                        <Link href={data.create_href}>
                            <Plus className="h-4 w-4" />
                            Créer une session
                        </Link>
                    </Button>
                )}
            </div>
            <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                <Tile label="Sessions à venir" value={data.indicators.upcoming} />
                <Tile label="Sessions en cours" value={data.indicators.running} />
                <Tile label="Terminées sur 12 mois" value={data.indicators.ended} />
                <Tile label="Stagiaires inscrits sur 12 mois" value={data.indicators.trainees} />
            </div>
            {data.sessions.length === 0 ? (
                <p className="text-muted-foreground text-sm">Aucune session à venir, en cours ou terminée sur les 12 derniers mois.</p>
            ) : (
                <ul className="divide-y rounded-xl border text-sm">
                    {data.sessions.map((s) => (
                        <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
                            <div className="grid gap-0.5">
                                <Link className="font-medium hover:underline" href={s.href}>
                                    {s.label}
                                </Link>
                                <span className="text-muted-foreground text-xs">
                                    {s.starts_on === s.ends_on ? formatDate(s.starts_on) : `${formatDate(s.starts_on)} au ${formatDate(s.ends_on)}`} ·{' '}
                                    {s.kind_label}
                                </span>
                            </div>
                            <Badge variant="outline">{s.status_label}</Badge>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function TrainingShowPage({ training, brochures, blocks, can, status }: ShowProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Catalogue', href: '/catalogue' },
        { title: training.title, href: '#' },
    ];
    const duration = [training.duration_hours && `${training.duration_hours} h`, training.duration_days && `${training.duration_days} j`]
        .filter(Boolean)
        .join(' · ');
    const facts: [string, string][] = [
        ['Code', training.code ?? '—'],
        ['DAS', training.das ?? '—'],
        ['Durée', duration || '—'],
        ['Modalité', training.modalities.length > 0 ? training.modalities.join(', ') : '—'],
        ['Tarif entreprise HT', euros(training.price_company)],
        ['Tarif particulier HT', euros(training.price_individual)],
        ['Financements', training.funding_modes.length > 0 ? training.funding_modes.join(', ') : '—'],
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={training.title} />
            <div className="flex flex-col gap-4 p-4">
                {status && <div className="text-sm font-medium text-green-600">{status}</div>}
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <Heading title={training.title} />
                        <Badge variant={training.is_active ? 'secondary' : 'outline'}>{training.is_active ? 'Active' : 'Désactivée'}</Badge>
                    </div>
                    {can.manage && (
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('catalogue.edit', training.id)}>Modifier</Link>
                        </Button>
                    )}
                </div>

                <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    {facts.map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-muted-foreground text-xs">{label}</dt>
                            <dd>{value}</dd>
                        </div>
                    ))}
                </dl>

                {blocks.map((b) => (b.key === 'sessions' ? <SessionsBlock key={b.key} data={b.data as unknown as SessionsBlockData} /> : null))}

                <section aria-label="Plaquettes" className="grid gap-2 rounded-xl border p-4">
                    <h2 className="text-sm font-semibold">Plaquettes ({brochures.length})</h2>
                    {brochures.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Aucune plaquette rattachée à cette formation ni à son DAS.</p>
                    ) : (
                        <ul className="grid gap-1 text-sm">
                            {brochures.map((b) => (
                                <li key={b.id} className="flex flex-wrap items-center gap-2">
                                    {can.brochures ? (
                                        <a className="underline" href={route('catalogue.brochures.download', b.id)}>
                                            {b.title}
                                        </a>
                                    ) : (
                                        <span>{b.title}</span>
                                    )}
                                    <span className="text-muted-foreground">
                                        {b.original_name} · {formatSize(b.size)} · {b.source === 'das' ? 'via le DAS' : 'rattachée à la formation'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
