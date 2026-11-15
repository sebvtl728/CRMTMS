import { mergeRoute, showRoute, type FicheType } from '@/components/crm/duplicate-warning';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeftRight, LoaderCircle, Merge } from 'lucide-react';
import { useState } from 'react';

type Side = 'keep' | 'drop';

interface FieldRow {
    name: string;
    label: string;
    keep: string | null;
    drop: string | null;
    same: boolean;
    default: Side;
}

interface Summary {
    id: string;
    name: string;
    created_at: string | null;
    das: string[];
}

interface Counts {
    notes: number;
    attachments: number;
    affiliations: number;
}

interface MergeProps {
    type: FicheType;
    keep: Summary;
    drop: Summary;
    fields: FieldRow[];
    counts: { keep: Counts; drop: Counts };
    /** « Inverser » allowed: the other fiche kept, this one absorbed. */
    canSwap: boolean;
}

function Value({ value }: { value: string | null }) {
    return value === null ? (
        <span className="text-muted-foreground italic">Vide</span>
    ) : (
        <span className="break-words whitespace-pre-line">{value}</span>
    );
}

function Choice({ field, side, checked, onChange }: { field: FieldRow; side: Side; checked: boolean; onChange: () => void }) {
    const id = `${field.name}-${side}`;

    return (
        <label
            htmlFor={id}
            className={`flex cursor-pointer items-start gap-2 rounded-md border p-2 text-sm ${checked ? 'border-primary bg-primary/5' : 'border-transparent'}`}
        >
            <input id={id} type="radio" name={field.name} className="mt-1" checked={checked} onChange={onChange} />
            <span className="min-w-0">
                <span className="text-muted-foreground block text-xs md:hidden">{side === 'keep' ? 'Fiche conservée' : 'Fiche absorbée'}</span>
                <Value value={field[side]} />
            </span>
        </label>
    );
}

export default function MergePage({ type, keep, drop, fields, counts, canSwap }: MergeProps) {
    const isOrg = type === 'organisation';
    const [choices, setChoices] = useState<Record<string, Side>>(() => Object.fromEntries(fields.map((f) => [f.name, f.default])));
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const errors = usePage().props.errors as Record<string, string | undefined>;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: isOrg ? 'Entreprises' : 'Personnes', href: isOrg ? '/crm/organisations' : '/crm/people' },
        { title: keep.name, href: showRoute(type, keep.id) },
        { title: 'Fusionner', href: '#' },
    ];

    const differing = fields.filter((f) => !f.same);
    const identical = fields.filter((f) => f.same && f.keep !== null);

    const submit = () => {
        router.post(
            route('crm.merge.store', { type }),
            { keep: keep.id, drop: drop.id, fields: Object.fromEntries(differing.map((f) => [f.name, choices[f.name]])) },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: () => setConfirming(false),
            },
        );
    };

    const header = (side: Side, s: Summary, c: Counts) => (
        <div className="grid gap-1 rounded-xl border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant={side === 'keep' ? 'default' : 'outline'}>{side === 'keep' ? 'Fiche conservée' : 'Fiche absorbée'}</Badge>
            </div>
            <Link href={showRoute(type, s.id)} className="font-semibold underline">
                {s.name}
            </Link>
            <p className="text-muted-foreground text-xs">Créée le {formatDate(s.created_at)}</p>
            <p className="text-muted-foreground text-xs">
                {c.notes} note(s) · {c.attachments} pièce(s) jointe(s) · {c.affiliations} {isOrg ? 'interlocuteur(s)' : 'rattachement(s)'}
                {s.das.length > 0 && <> · DAS : {s.das.join(', ')}</>}
            </p>
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Fusionner deux fiches" />

            <div className="flex max-w-5xl flex-col gap-6 p-4">
                <Heading
                    title="Fusionner deux fiches"
                    description="Choisissez, pour chaque champ différent, la valeur à garder. Notes, pièces jointes, rattachements et DAS de la fiche absorbée rejoignent la fiche conservée."
                />

                <div className="grid gap-3 md:grid-cols-2">
                    {header('keep', keep, counts.keep)}
                    {header('drop', drop, counts.drop)}
                </div>
                {canSwap && (
                    <div>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={mergeRoute(type, drop.id, keep.id)}>
                                <ArrowLeftRight className="h-4 w-4" aria-hidden />
                                Inverser : conserver l'autre fiche
                            </Link>
                        </Button>
                    </div>
                )}

                <InputError message={errors.keep ?? errors.drop ?? errors.fields} />

                <section aria-labelledby="differing-title" className="grid gap-2">
                    <h2 id="differing-title" className="font-semibold">
                        Champs différents ({differing.length})
                    </h2>
                    {differing.length === 0 && <p className="text-muted-foreground text-sm">Les deux fiches ont les mêmes valeurs.</p>}
                    {differing.length > 0 && (
                        <div className="hidden grid-cols-[12rem_1fr_1fr] gap-2 px-2 text-xs font-medium md:grid">
                            <span>Champ</span>
                            <span>Fiche conservée</span>
                            <span>Fiche absorbée</span>
                        </div>
                    )}
                    {differing.map((f) => (
                        <fieldset key={f.name} className="grid gap-1 rounded-lg border p-2 md:grid-cols-[12rem_1fr_1fr] md:items-start md:gap-2">
                            <legend className="sr-only">{f.label}</legend>
                            <span className="px-2 pt-2 text-sm font-medium" aria-hidden>
                                {f.label}
                            </span>
                            <Choice
                                field={f}
                                side="keep"
                                checked={choices[f.name] === 'keep'}
                                onChange={() => setChoices({ ...choices, [f.name]: 'keep' })}
                            />
                            <Choice
                                field={f}
                                side="drop"
                                checked={choices[f.name] === 'drop'}
                                onChange={() => setChoices({ ...choices, [f.name]: 'drop' })}
                            />
                            <InputError message={errors[`fields.${f.name}`]} className="md:col-span-3" />
                        </fieldset>
                    ))}
                </section>

                {identical.length > 0 && (
                    <details className="rounded-lg border p-3 text-sm">
                        <summary className="cursor-pointer font-medium">Champs identiques ({identical.length})</summary>
                        <dl className="mt-2 grid gap-1 md:grid-cols-[12rem_1fr]">
                            {identical.map((f) => (
                                <div key={f.name} className="contents">
                                    <dt className="text-muted-foreground">{f.label}</dt>
                                    <dd>
                                        <Value value={f.keep} />
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </details>
                )}

                <div className="flex flex-wrap gap-2">
                    <Button type="button" onClick={() => setConfirming(true)}>
                        <Merge className="h-4 w-4" aria-hidden />
                        Fusionner
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={showRoute(type, keep.id)}>Annuler</Link>
                    </Button>
                </div>
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cette fusion est définitive</DialogTitle>
                        <DialogDescription>
                            « {drop.name} » sera absorbée par « {keep.name} » : son historique, ses pièces jointes et ses rattachements seront
                            déplacés, puis elle disparaîtra définitivement (son adresse renverra vers la fiche conservée). Cette opération ne peut pas
                            être annulée.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirming(false)} disabled={processing}>
                            Annuler
                        </Button>
                        <Button onClick={submit} disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Fusionner définitivement
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
