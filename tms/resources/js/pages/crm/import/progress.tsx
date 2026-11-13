import { WizardPage, type BatchSummary } from '@/components/crm/import-wizard';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Progress {
    status: string;
    label: string;
    phase: 'simulation' | 'run' | 'undo' | null;
    processed: number;
    total: number | null;
    percent: number | null;
    stuck: boolean;
    canFail: boolean;
    finished: boolean;
    failure: string | null;
}

interface ProgressProps extends Progress {
    batch: BatchSummary;
}

const POLL_MS = 2000;
const number = new Intl.NumberFormat('fr-FR');

const TEXT = {
    simulation: { step: 4, title: 'Analyse du fichier', verb: 'lignes analysées', hint: "Le fichier est vérifié ligne par ligne. Rien n'est créé." },
    run: {
        step: 5,
        title: 'Import en cours',
        verb: 'lignes traitées',
        hint: "Vous pouvez quitter cette page : l'import continue en arrière-plan et reste visible dans « Imports ».",
    },
    undo: { step: 6, title: 'Annulation en cours', verb: 'fiches traitées', hint: 'Les fiches créées par cet import sont retirées.' },
} as const;

/** Polls the batch every 2 s and reloads the page when its step changes. */
export default function ImportProgress(props: ProgressProps) {
    const { batch } = props;
    const [progress, setProgress] = useState<Progress>(props);
    const [confirming, setConfirming] = useState(false);
    const errors = usePage<{ errors: Record<string, string> }>().props.errors ?? {};
    const text = TEXT[progress.phase ?? props.phase ?? 'run'];

    useEffect(() => {
        let stopped = false;
        const tick = async () => {
            try {
                const response = await fetch(route('crm.import.status', batch.id), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) return;
                const data = (await response.json()) as Progress;
                if (stopped) return;
                if (data.finished) {
                    router.visit(route('crm.import.show', batch.id), { replace: true });
                    return;
                }
                setProgress(data);
            } catch {
                // Network hiccup: next tick.
            }
        };
        const timer = setInterval(tick, POLL_MS);

        return () => {
            stopped = true;
            clearInterval(timer);
        };
    }, [batch.id]);

    return (
        <WizardPage
            step={text.step}
            title={progress.stuck ? 'Import bloqué' : text.title}
            description={`« ${batch.original_name} » · ${batch.target.label}`}
        >
            <div className="grid max-w-3xl gap-4" aria-live="polite">
                {errors.batch && (
                    <Alert variant="destructive">
                        <AlertTriangle className="h-4 w-4" />
                        <AlertDescription>{errors.batch}</AlertDescription>
                    </Alert>
                )}
                {progress.stuck ? (
                    <Alert variant="destructive">
                        <AlertTriangle className="h-4 w-4" />
                        <AlertTitle>Bloqué : plus aucune avancée depuis 15 minutes</AlertTitle>
                        <AlertDescription>
                            <p>
                                Le traitement semble interrompu. Vous pouvez le marquer comme échoué : les fiches déjà créées seront conservées et
                                pourront être annulées depuis la page « Imports ».
                            </p>
                            {progress.canFail && (
                                <Button variant="destructive" size="sm" className="mt-3" onClick={() => setConfirming(true)}>
                                    Marquer comme échoué
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
                ) : (
                    <div className="flex items-center gap-2 text-sm">
                        <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
                        <span>{progress.label}…</span>
                    </div>
                )}

                <div
                    role="progressbar"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={progress.percent ?? undefined}
                    aria-label="Avancement"
                    className="bg-muted h-3 w-full overflow-hidden rounded-full"
                >
                    <div
                        className={'bg-primary h-full rounded-full transition-all ' + (progress.percent === null ? 'w-1/3 animate-pulse' : '')}
                        style={progress.percent === null ? undefined : { width: `${progress.percent}%` }}
                    />
                </div>
                <p className="text-sm">
                    {number.format(progress.processed)}
                    {progress.total ? ` sur ${number.format(progress.total)}` : ''} {text.verb}
                    {progress.percent !== null ? ` (${progress.percent} %)` : ''}
                </p>
                <p className="text-muted-foreground text-xs">{text.hint}</p>
            </div>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Marquer cet import comme échoué ?</DialogTitle>
                        <DialogDescription>
                            L&apos;import ne sera pas repris. Les fiches déjà créées restent en place et pourront être annulées ensuite.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirming(false)}>
                            Attendre encore
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => router.post(route('crm.import.fail', batch.id), {}, { onSuccess: () => setConfirming(false) })}
                        >
                            Marquer comme échoué
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </WizardPage>
    );
}
