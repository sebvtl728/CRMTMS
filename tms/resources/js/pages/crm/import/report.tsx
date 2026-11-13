import { Counter, LineList, StatusBadge, UndoDialog, WizardPage, type BatchSummary } from '@/components/crm/import-wizard';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/crm';
import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, Download } from 'lucide-react';
import { useState } from 'react';

interface ReportProps {
    batch: BatchSummary;
    rows: { line: number; status: string; messages: string[] }[];
    rowsTotal: number;
    canUndo: boolean;
    hasReport: boolean;
    flash?: string | null;
}

const number = new Intl.NumberFormat('fr-FR');

export default function ImportReport({ batch, rows, rowsTotal, canUndo, hasReport, flash }: ReportProps) {
    const [undoing, setUndoing] = useState(false);
    const page = usePage<{ errors: Record<string, string> }>();
    const errors = page.props.errors ?? {};
    const listUrl = batch.target.value === 'organisations' ? route('crm.organisations.index') : route('crm.people.index');
    const failed = batch.status.value === 'failed';
    const undone = batch.status.value === 'undone';

    return (
        <WizardPage
            step={6}
            title="Rapport d'import"
            description={`« ${batch.original_name} » · ${batch.target.label} · ${formatDateTime(batch.finished_at ?? batch.created_at)}`}
        >
            <div className="grid max-w-4xl gap-5">
                <div className="flex flex-wrap items-center gap-3">
                    <StatusBadge status={batch.status} />
                    {batch.author && <span className="text-muted-foreground text-sm">par {batch.author}</span>}
                </div>

                {flash && <div className="text-sm font-medium text-green-600">{flash}</div>}

                {errors.batch && (
                    <Alert variant="destructive">
                        <AlertTriangle className="h-4 w-4" />
                        <AlertDescription>{errors.batch}</AlertDescription>
                    </Alert>
                )}

                {failed && (
                    <Alert variant="destructive">
                        <AlertTriangle className="h-4 w-4" />
                        <AlertTitle>L&apos;import s&apos;est arrêté</AlertTitle>
                        <AlertDescription>
                            {batch.failure ?? 'Une erreur est survenue.'} Les lignes traitées avant l&apos;arrêt sont conservées
                            {batch.created > 0 ? ' et peuvent être annulées.' : '.'}
                        </AlertDescription>
                    </Alert>
                )}

                {undone && batch.undo && (
                    <Alert>
                        <AlertTitle>Import annulé le {formatDateTime(batch.undone_at)}</AlertTitle>
                        <AlertDescription>
                            {number.format(batch.undo.removed)} fiche(s) retirée(s) (placées dans la corbeille) ; {number.format(batch.undo.kept)}{' '}
                            conservée(s) car modifiées depuis l&apos;import ou reliées à des données ajoutées depuis.
                        </AlertDescription>
                    </Alert>
                )}

                <section aria-label="Résultat" className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Counter label="Fiches créées" value={batch.created} tone="good" />
                    <Counter label="Fiches mises à jour" value={batch.updated} />
                    <Counter label="Lignes ignorées" value={batch.skipped} tone={batch.skipped > 0 ? 'warning' : 'default'} />
                    <Counter label="Lignes en erreur" value={batch.errors} tone={batch.errors > 0 ? 'danger' : 'default'} />
                </section>

                <div className="flex flex-wrap items-center gap-3">
                    {hasReport && (
                        <Button asChild>
                            <a href={route('crm.import.report', batch.id)}>
                                <Download className="h-4 w-4" />
                                Télécharger le rapport (Excel)
                            </a>
                        </Button>
                    )}
                    {!undone && batch.created > 0 && (
                        <Button asChild variant="outline">
                            <Link href={listUrl}>Voir la liste</Link>
                        </Button>
                    )}
                    {canUndo && (
                        <Button variant="outline" onClick={() => setUndoing(true)}>
                            Annuler l&apos;import
                        </Button>
                    )}
                    <Button asChild variant="ghost">
                        <Link href={route('crm.import.index')}>Tous les imports</Link>
                    </Button>
                </div>

                <section aria-label="Lignes à vérifier" className="grid gap-2">
                    <h3 className="font-medium">
                        Lignes en erreur ou ignorées
                        {rowsTotal > rows.length
                            ? ` (les ${rows.length} premières sur ${number.format(rowsTotal)} : le rapport Excel les contient toutes)`
                            : ''}
                    </h3>
                    <LineList rows={rows} empty="Aucune ligne à vérifier." />
                </section>
            </div>

            {undoing && <UndoDialog batchId={batch.id} open onOpenChange={setUndoing} />}
        </WizardPage>
    );
}
