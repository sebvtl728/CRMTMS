import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { type IndicatorRow, STATUS_STYLES } from '@/components/qualite/qualiopi';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatSize } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ExternalLink, FileText, LoaderCircle, StickyNote, Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Proof {
    id: string;
    kind: 'file' | 'link' | 'note';
    title: string;
    url: string | null;
    note: string | null;
    original_name: string | null;
    size: number | null;
    created_at: string | null;
}

interface Measure {
    key: string;
    label: string;
    value: string;
    detail: string | null;
    tone: 'ok' | 'warn' | 'neutral';
}

interface Props {
    indicator: IndicatorRow;
    criterion: { number: number; title: string };
    proofs: Proof[];
    measures: Measure[];
    users: { value: string; label: string }[];
    statuses: Record<string, string>;
    verify_notice: string;
    can: { manage: boolean };
    status?: string;
}

/** The menu prefetches the list (Inertia cache): drop it once the file changed, or « Retour » would show it stale. */
const flushLists = () => router.flushAll();

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const TONES: Record<string, string> = { ok: 'text-green-700 dark:text-green-400', warn: 'text-amber-700 dark:text-amber-400', neutral: '' };

/** The indicator's state, as kept by the quality team. */
function StateForm({ indicator, users, statuses, can }: Pick<Props, 'indicator' | 'users' | 'statuses' | 'can'>) {
    const { data, setData, put, processing, errors } = useForm({
        applicable: indicator.applicable,
        not_applicable_reason: indicator.not_applicable_reason ?? '',
        status: indicator.status,
        responsible_user_id: indicator.responsible_user_id ?? '',
        review_on: indicator.review_on ?? '',
        notes: indicator.notes ?? '',
    });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/qualite/qualiopi/${indicator.number}`, { preserveScroll: true, onSuccess: flushLists });
    };
    const disabled = !can.manage;

    return (
        <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4">
            <h2 className="font-semibold">Suivi</h2>
            <Label className="flex items-center gap-2 font-normal">
                <input type="checkbox" checked={!data.applicable} disabled={disabled} onChange={(e) => setData('applicable', !e.target.checked)} />
                Indicateur non applicable à l’organisme
            </Label>
            {!data.applicable && (
                <div className="grid gap-1">
                    <Label htmlFor="reason">Justification (obligatoire, présentée à l’auditeur)</Label>
                    <textarea
                        id="reason"
                        className="border-input bg-background min-h-16 rounded-md border p-2 text-sm"
                        value={data.not_applicable_reason}
                        disabled={disabled}
                        onChange={(e) => setData('not_applicable_reason', e.target.value)}
                    />
                    <InputError message={errors.not_applicable_reason} />
                </div>
            )}
            <div className="grid gap-3 sm:grid-cols-3">
                <div className="grid gap-1">
                    <Label htmlFor="status">Statut</Label>
                    <select
                        id="status"
                        className={selectClass}
                        value={data.status}
                        disabled={disabled}
                        onChange={(e) => setData('status', e.target.value as IndicatorRow['status'])}
                    >
                        {Object.entries(statuses).map(([k, l]) => (
                            <option key={k} value={k}>
                                {l}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.status} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="responsible">Responsable</Label>
                    <select
                        id="responsible"
                        className={selectClass}
                        value={data.responsible_user_id}
                        disabled={disabled}
                        onChange={(e) => setData('responsible_user_id', e.target.value)}
                    >
                        <option value="">-</option>
                        {users.map((u) => (
                            <option key={u.value} value={u.value}>
                                {u.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.responsible_user_id} />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="review_on">Date de revue</Label>
                    <Input
                        id="review_on"
                        type="date"
                        value={data.review_on}
                        disabled={disabled}
                        onChange={(e) => setData('review_on', e.target.value)}
                    />
                    <InputError message={errors.review_on} />
                </div>
            </div>
            <div className="grid gap-1">
                <Label htmlFor="notes">Notes internes</Label>
                <textarea
                    id="notes"
                    className="border-input bg-background min-h-20 rounded-md border p-2 text-sm"
                    value={data.notes}
                    disabled={disabled}
                    onChange={(e) => setData('notes', e.target.value)}
                />
                <InputError message={errors.notes} />
            </div>
            {can.manage && (
                <div>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Enregistrer
                    </Button>
                </div>
            )}
        </form>
    );
}

/** « Ajouter une preuve »: a file, a link or a note. */
function AddProof({ number }: { number: number }) {
    const { data, setData, post, processing, errors, reset, progress } = useForm<{
        kind: Proof['kind'];
        title: string;
        url: string;
        note: string;
        file: File | null;
    }>({
        kind: 'file',
        title: '',
        url: '',
        note: '',
        file: null,
    });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/qualite/qualiopi/${number}/proofs`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                flushLists();
            },
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-2 rounded-md border p-3">
            <div className="grid gap-2 sm:grid-cols-[10rem_1fr]">
                <div className="grid gap-1">
                    <Label htmlFor="kind">Type</Label>
                    <select id="kind" className={selectClass} value={data.kind} onChange={(e) => setData('kind', e.target.value as Proof['kind'])}>
                        <option value="file">Fichier</option>
                        <option value="link">Lien</option>
                        <option value="note">Note</option>
                    </select>
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="title">Titre</Label>
                    <Input
                        id="title"
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        placeholder="Ex. : procédure de positionnement"
                    />
                    <InputError message={errors.title} />
                </div>
            </div>
            {data.kind === 'file' && (
                <div className="grid gap-1">
                    <Label htmlFor="file">Fichier (PDF, image, document bureautique ; 20 Mo au plus)</Label>
                    <Input id="file" type="file" onChange={(e) => setData('file', e.target.files?.[0] ?? null)} />
                    <InputError message={errors.file} />
                </div>
            )}
            {data.kind === 'link' && (
                <div className="grid gap-1">
                    <Label htmlFor="url">Adresse (https://…)</Label>
                    <Input id="url" type="url" value={data.url} onChange={(e) => setData('url', e.target.value)} />
                    <InputError message={errors.url} />
                </div>
            )}
            {data.kind === 'note' && (
                <div className="grid gap-1">
                    <Label htmlFor="note">Note</Label>
                    <textarea
                        id="note"
                        className="border-input bg-background min-h-16 rounded-md border p-2 text-sm"
                        value={data.note}
                        onChange={(e) => setData('note', e.target.value)}
                    />
                    <InputError message={errors.note} />
                </div>
            )}
            <InputError message={errors.kind} />
            {progress && <progress value={progress.percentage} max="100" className="w-full" />}
            <div>
                <Button type="submit" variant="outline" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Ajouter la preuve
                </Button>
            </div>
        </form>
    );
}

export default function QualiopiShow({ indicator, criterion, proofs, measures, users, statuses, verify_notice, can }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Qualiopi', href: '/qualite/qualiopi' },
        { title: `Indicateur ${indicator.number}`, href: `/qualite/qualiopi/${indicator.number}` },
    ];
    const remove = (proof: Proof) => {
        if (window.confirm(`Supprimer la preuve « ${proof.title} » ?`)) {
            router.delete(`/qualite/qualiopi/proofs/${proof.id}`, { preserveScroll: true, onSuccess: flushLists });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Qualiopi - indicateur ${indicator.number}`} />
            <div className="flex max-w-4xl flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Heading
                        title={`Indicateur ${indicator.number} - ${indicator.title}`}
                        description={`Critère ${criterion.number} - ${criterion.title}`}
                    />
                </div>
                <div className="flex flex-wrap gap-2">
                    {indicator.applicable ? (
                        <span className={`rounded-full px-2 py-0.5 text-xs ${STATUS_STYLES[indicator.status]}`}>{statuses[indicator.status]}</span>
                    ) : (
                        <span className="rounded-full border px-2 py-0.5 text-xs">Non applicable</span>
                    )}
                    {indicator.change_label && <Badge variant="outline">{indicator.change_label} en 2026</Badge>}
                    <Badge variant="outline">{indicator.scope_label}</Badge>
                </div>

                <section className="bg-muted/40 rounded-xl border p-4">
                    <h2 className="mb-1 text-sm font-semibold">Texte officiel</h2>
                    <p className="text-sm whitespace-pre-wrap">{indicator.text}</p>
                    <p className="text-muted-foreground mt-2 text-xs">{verify_notice}</p>
                </section>

                {measures.length > 0 && (
                    <section className="grid gap-2 rounded-xl border p-4">
                        <h2 className="font-semibold">Mesures automatiques</h2>
                        <ul className="grid gap-1 text-sm">
                            {measures.map((m) => (
                                <li key={m.key}>
                                    {m.label} : <strong className={TONES[m.tone]}>{m.value}</strong>
                                    {m.detail && <span className="text-muted-foreground"> - {m.detail}</span>}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <StateForm indicator={indicator} users={users} statuses={statuses} can={can} />

                <section className="grid gap-2 rounded-xl border p-4">
                    <h2 className="font-semibold">Preuves</h2>
                    {proofs.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Aucune preuve pour l’instant.</p>
                    ) : (
                        <ul className="divide-y">
                            {proofs.map((p) => (
                                <li key={p.id} className="flex items-start gap-2 py-2 text-sm">
                                    {p.kind === 'file' ? (
                                        <FileText className="mt-0.5 h-4 w-4" />
                                    ) : p.kind === 'link' ? (
                                        <ExternalLink className="mt-0.5 h-4 w-4" />
                                    ) : (
                                        <StickyNote className="mt-0.5 h-4 w-4" />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        {p.kind === 'file' ? (
                                            <a
                                                href={`/qualite/qualiopi/proofs/${p.id}`}
                                                target="_blank"
                                                rel="noopener"
                                                className="font-medium underline"
                                            >
                                                {p.title}
                                            </a>
                                        ) : p.kind === 'link' ? (
                                            <a href={p.url ?? '#'} target="_blank" rel="noopener noreferrer" className="font-medium underline">
                                                {p.title}
                                            </a>
                                        ) : (
                                            <span className="font-medium">{p.title}</span>
                                        )}
                                        <span className="text-muted-foreground text-xs">
                                            {' '}
                                            - {formatDate(p.created_at)}
                                            {p.kind === 'file' && p.original_name ? ` · ${p.original_name} · ${formatSize(p.size)}` : ''}
                                        </span>
                                        {p.kind === 'note' && <p className="whitespace-pre-wrap">{p.note}</p>}
                                    </div>
                                    {can.manage && (
                                        <Button size="sm" variant="ghost" aria-label={`Supprimer ${p.title}`} onClick={() => remove(p)}>
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {can.manage && <AddProof number={indicator.number} />}
                </section>

                <div>
                    <Link href="/qualite/qualiopi" className="text-sm underline">
                        ← Retour aux 33 indicateurs
                    </Link>
                </div>
            </div>
        </AppLayout>
    );
}
