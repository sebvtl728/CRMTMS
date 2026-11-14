import { Counter, LineList, WizardPage, type BatchSummary } from '@/components/crm/import-wizard';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Info } from 'lucide-react';
import { useState } from 'react';

interface Line {
    line: number;
    status: string;
    messages: string[];
}

interface Simulation {
    total: number;
    to_create: number;
    to_update: number;
    to_skip: number;
    errors: number;
    modified_since_import: number;
    blocking: string[];
    warnings: string[];
    error_rows: Line[];
    other_rows: Line[];
}

interface SimulationProps {
    batch: BatchSummary;
    simulation: Simulation;
    unknownCount: number;
    hasValues: boolean;
    strategy: { value: string; options: { value: string; label: string; description: string }[] };
    canRun: boolean;
    sourceSystem: string | null;
}

const number = new Intl.NumberFormat('fr-FR');

export default function ImportSimulation({ batch, simulation, unknownCount, hasValues, strategy, canRun, sourceSystem }: SimulationProps) {
    const catalogue = batch.target.value === 'trainings';
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const importable = simulation.to_create + simulation.to_update;

    const changeStrategy = (value: string) => {
        if (value !== strategy.value) {
            setBusy(true);
            router.put(route('crm.import.strategy.save', batch.id), { strategy: value }, { onFinish: () => setBusy(false) });
        }
    };

    return (
        <WizardPage
            step={4}
            title="Vérifier la simulation"
            description="Voici ce que ferait l'import. Rien n'a été créé ni modifié : vous pouvez corriger votre fichier ou vos choix avant de continuer."
        >
            <div className="grid max-w-4xl gap-5">
                <p className="text-muted-foreground text-sm">
                    Fichier « {batch.original_name} » · {batch.target.label} · {number.format(simulation.total)} ligne(s) lue(s)
                    {sourceSystem ? ` · identifiants de ${sourceSystem}` : ''}
                </p>

                {simulation.blocking.length > 0 && (
                    <Alert variant="destructive">
                        <AlertTriangle className="h-4 w-4" />
                        <AlertTitle>L&apos;import ne peut pas démarrer</AlertTitle>
                        <AlertDescription>
                            <ul className="list-disc pl-4">
                                {simulation.blocking.map((b) => (
                                    <li key={b}>{b}</li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                <section aria-label="Résultat de la simulation" className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Counter label="Fiches créées" value={simulation.to_create} tone="good" />
                    <Counter label="Fiches mises à jour" value={simulation.to_update} />
                    <Counter label="Lignes ignorées" value={simulation.to_skip} tone={simulation.to_skip > 0 ? 'warning' : 'default'} />
                    <Counter label="Lignes en erreur" value={simulation.errors} tone={simulation.errors > 0 ? 'danger' : 'default'} />
                </section>

                {simulation.to_update > 0 && (
                    <Alert>
                        <Info className="h-4 w-4" />
                        <AlertTitle>Des fiches existantes seront modifiées</AlertTitle>
                        <AlertDescription>
                            {number.format(simulation.to_update)} fiche(s) déjà présente(s) seront mises à jour avec les valeurs du fichier, même si
                            ces valeurs sont différentes de ce qui est saisi aujourd&apos;hui (les cellules vides du fichier ne suppriment rien).
                            {simulation.modified_since_import > 0 &&
                                ` Parmi elles, ${number.format(simulation.modified_since_import)} ont été modifiées à la main depuis leur import précédent : ces modifications seront écrasées.`}
                        </AlertDescription>
                    </Alert>
                )}

                {simulation.warnings.length > 0 && (
                    <ul className="text-muted-foreground list-disc pl-5 text-sm">
                        {simulation.warnings.map((w) => (
                            <li key={w}>{w}</li>
                        ))}
                    </ul>
                )}

                {unknownCount > 0 && hasValues && (
                    <Alert>
                        <Info className="h-4 w-4" />
                        <AlertTitle>Valeurs sans correspondance</AlertTitle>
                        <AlertDescription>
                            {number.format(unknownCount)} valeur(s) du fichier ne correspondent à rien : les lignes concernées sont en erreur.{' '}
                            <Link href={route('crm.import.values', batch.id)} className="underline">
                                Associer les valeurs
                            </Link>
                        </AlertDescription>
                    </Alert>
                )}

                <fieldset className="grid gap-2" disabled={busy}>
                    <legend className="mb-1 text-sm font-medium">
                        {catalogue ? 'Que faire quand la formation existe déjà ?' : 'Que faire quand la fiche existe déjà ?'}
                    </legend>
                    <p className="text-muted-foreground text-xs">
                        {catalogue
                            ? 'Une formation est considérée comme existante si une formation du catalogue a le même code. Une formation de même identifiant source est toujours mise à jour. Le choix relance la simulation.'
                            : "Une fiche est considérée comme existante si le SIRET, l'e-mail ou le téléphone est identique, ou si le nom est le même au même endroit. Le choix relance la simulation."}
                    </p>
                    {strategy.options.map((o) => (
                        <label
                            key={o.value}
                            className={
                                'hover:bg-muted/30 flex cursor-pointer gap-3 rounded-xl border p-3 ' +
                                (strategy.value === o.value ? 'border-primary ring-primary ring-1' : '')
                            }
                        >
                            <input
                                type="radio"
                                name="strategy"
                                value={o.value}
                                checked={strategy.value === o.value}
                                onChange={() => changeStrategy(o.value)}
                                className="mt-1"
                            />
                            <span>
                                <span className="block font-medium">{o.label}</span>
                                <span className="text-muted-foreground block text-sm">{o.description}</span>
                            </span>
                        </label>
                    ))}
                    <InputError message={errors.strategy} />
                </fieldset>

                <section aria-label="Lignes en erreur" className="grid gap-2">
                    <h3 className="font-medium">
                        Lignes en erreur
                        {simulation.errors > 0
                            ? ` (${simulation.error_rows.length < simulation.errors ? `les ${simulation.error_rows.length} premières sur ${number.format(simulation.errors)}` : number.format(simulation.errors)})`
                            : ''}
                    </h3>
                    <p className="text-muted-foreground text-xs">
                        Ces lignes ne seront pas importées. Les numéros sont ceux de votre tableur (la ligne 1 contient les titres).
                    </p>
                    <LineList rows={simulation.error_rows} empty="Aucune erreur." />
                </section>

                {simulation.other_rows.length > 0 && (
                    <details className="grid gap-2">
                        <summary className="cursor-pointer font-medium">
                            Lignes ignorées ou avec un avertissement ({simulation.other_rows.length} affichées)
                        </summary>
                        <div className="mt-2">
                            <LineList rows={simulation.other_rows} />
                        </div>
                    </details>
                )}

                <div className="flex flex-wrap items-center gap-3">
                    <Button type="button" disabled={!canRun || busy || importable === 0} onClick={() => setConfirming(true)}>
                        Lancer l&apos;import
                    </Button>
                    <Button asChild variant="outline">
                        <Link href={route('crm.import.mapping', batch.id)}>Retour aux colonnes</Link>
                    </Button>
                    {hasValues && (
                        <Button asChild variant="ghost">
                            <Link href={route('crm.import.values', batch.id)}>Revoir les valeurs</Link>
                        </Button>
                    )}
                    {importable === 0 && simulation.blocking.length === 0 && (
                        <span className="text-muted-foreground text-sm">Aucune fiche à créer ou mettre à jour.</span>
                    )}
                </div>
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Lancer l&apos;import ?</DialogTitle>
                        <DialogDescription>
                            {number.format(simulation.to_create)} fiche(s) seront créées et {number.format(simulation.to_update)} mises à jour. Vous
                            pourrez annuler cet import depuis la page « Imports » : les fiches créées seront retirées, sauf celles modifiées
                            entre-temps.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirming(false)}>
                            Pas maintenant
                        </Button>
                        <Button
                            onClick={() =>
                                router.post(route('crm.import.run', batch.id), {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })
                            }
                        >
                            Lancer l&apos;import
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </WizardPage>
    );
}
