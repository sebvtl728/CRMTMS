import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/react';
import { GraduationCap, Pencil } from 'lucide-react';
import { useMemo, useState } from 'react';

export interface InterestTraining {
    id: string;
    code: string | null;
    title: string;
    das_id: string;
    das: string | null;
    is_active: boolean;
}

export interface InterestTrainingOption {
    value: string;
    label: string;
    das_id: string;
    das: string | null;
    is_active: boolean;
}

const MAX = 50;

/**
 * « Formations d'intérêt » of an entreprise or a person: the trainings shown
 * by DAS (an inactive one greyed), and an editor among the active trainings
 * of the catalogue, limited to the DAS of the fiche unless « Afficher toutes
 * les formations » is ticked.
 */
export function InterestTrainings({
    trainings,
    options,
    ficheDasIds,
    updateUrl,
    canUpdate,
}: {
    trainings: InterestTraining[];
    options: InterestTrainingOption[];
    ficheDasIds: string[];
    updateUrl: string;
    canUpdate: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [selected, setSelected] = useState<string[]>([]);
    const [showAll, setShowAll] = useState(false);
    const [search, setSearch] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const start = () => {
        setSelected(trainings.map((t) => t.id));
        setShowAll(ficheDasIds.length === 0);
        setSearch('');
        setError(null);
        setOpen(true);
    };

    const groups = useMemo(() => {
        const words = search.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').split(/\s+/).filter(Boolean);
        const visible = options.filter((o) => {
            if (!showAll && !ficheDasIds.includes(o.das_id) && !selected.includes(o.value)) return false;
            const label = o.label.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

            return words.every((w) => label.includes(w));
        });
        const byDas = new Map<string, { das: string; items: InterestTrainingOption[] }>();
        visible.forEach((o) => {
            const group = byDas.get(o.das_id) ?? { das: o.das ?? 'Sans DAS', items: [] };
            group.items.push(o);
            byDas.set(o.das_id, group);
        });

        return [...byDas.entries()].map(([id, g]) => ({ id, ...g }));
    }, [options, showAll, ficheDasIds, selected, search]);

    const toggle = (id: string, checked: boolean) => setSelected((current) => (checked ? [...current, id] : current.filter((v) => v !== id)));

    const save = () => {
        if (selected.length > MAX) {
            setError(`Indiquez au plus ${MAX} formations d'intérêt.`);
            return;
        }
        setSaving(true);
        router.put(
            updateUrl,
            { training_ids: selected },
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
                onError: (errors) => setError(Object.values(errors)[0] ?? "Les formations d'intérêt n'ont pas pu être enregistrées."),
                onFinish: () => setSaving(false),
            },
        );
    };

    const byDas = trainings.reduce<Record<string, InterestTraining[]>>((acc, t) => {
        const key = t.das ?? 'Sans DAS';
        (acc[key] ??= []).push(t);

        return acc;
    }, {});

    return (
        <section className="rounded-xl border p-4 lg:col-span-2" aria-labelledby="interest-trainings-title" data-testid="interest-trainings">
            <div className="mb-3 flex items-center justify-between gap-2">
                <h2 id="interest-trainings-title" className="text-sm font-semibold">
                    Formations d'intérêt
                </h2>
                {canUpdate && (
                    <Button variant="outline" size="sm" onClick={start}>
                        <Pencil className="h-4 w-4" />
                        Modifier
                    </Button>
                )}
            </div>

            {trainings.length === 0 ? (
                <p className="text-muted-foreground text-sm">Aucune formation d'intérêt.</p>
            ) : (
                <dl className="grid gap-3 sm:grid-cols-2">
                    {Object.entries(byDas).map(([das, items]) => (
                        <div key={das}>
                            <dt className="text-muted-foreground text-xs">{das}</dt>
                            <dd className="mt-1 flex flex-wrap gap-1">
                                {items.map((t) => (
                                    <Badge
                                        key={t.id}
                                        variant="outline"
                                        className={t.is_active ? '' : 'text-muted-foreground line-through opacity-70'}
                                        title={t.is_active ? undefined : 'Formation désactivée dans le catalogue'}
                                    >
                                        <GraduationCap className="h-3 w-3" />
                                        {t.title}
                                        {t.code ? ` (${t.code})` : ''}
                                        {!t.is_active && ' (inactive)'}
                                    </Badge>
                                ))}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Formations d'intérêt</DialogTitle>
                        <DialogDescription>
                            Cochez les formations du catalogue qui intéressent cette fiche
                            {ficheDasIds.length > 0 ? ' (par défaut : celles de ses DAS).' : '.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <Input
                            aria-label="Rechercher une formation"
                            placeholder="Rechercher une formation"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        {ficheDasIds.length > 0 && (
                            <Label className="flex items-center gap-2 font-normal">
                                <Checkbox checked={showAll} onCheckedChange={(c) => setShowAll(c === true)} />
                                Afficher toutes les formations
                            </Label>
                        )}

                        {groups.length === 0 && <p className="text-muted-foreground text-sm">Aucune formation à proposer.</p>}
                        {groups.map((g) => (
                            <fieldset key={g.id} className="grid gap-1">
                                <legend className="text-muted-foreground mb-1 text-xs font-medium">{g.das}</legend>
                                {g.items.map((o) => (
                                    <Label
                                        key={o.value}
                                        className={`flex items-center gap-2 font-normal ${o.is_active ? '' : 'text-muted-foreground'}`}
                                    >
                                        <Checkbox checked={selected.includes(o.value)} onCheckedChange={(c) => toggle(o.value, c === true)} />
                                        {o.label}
                                    </Label>
                                ))}
                            </fieldset>
                        ))}

                        {error && (
                            <p role="alert" className="text-sm text-red-600">
                                {error}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <span className="text-muted-foreground mr-auto self-center text-xs">
                            {selected.length} sélectionnée{selected.length > 1 ? 's' : ''}
                        </span>
                        <Button variant="outline" onClick={() => setOpen(false)}>
                            Annuler
                        </Button>
                        <Button onClick={save} disabled={saving}>
                            Enregistrer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}
