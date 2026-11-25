import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type Candidature, STAGE_RANK } from '@/types/admissions';
import { Check } from 'lucide-react';

const STEPS: { key: string; label: string; anchor: string }[] = [
    { key: 'identified', label: 'Identifié', anchor: 'carte-orientation' },
    { key: 'positioning_sent', label: 'Test envoyé', anchor: 'carte-test' },
    { key: 'positioning_received', label: 'Test reçu', anchor: 'carte-test' },
    { key: 'interview', label: 'Entretien', anchor: 'carte-entretien' },
    { key: 'admitted', label: 'Admis', anchor: 'carte-dossier' },
    { key: 'dossier', label: 'Dossier créé', anchor: 'carte-dossier' },
];

/** The next thing to do on the candidature, read from its stage (null once out of the path). */
export function nextAction(c: Candidature): { label: string; anchor: string } | null {
    if (c.dossier && !c.dossier.trashed) return { label: 'Suivre la formation', anchor: 'carte-suivi' };
    switch (c.status) {
        case 'identified':
            return { label: 'Envoyer le test de positionnement', anchor: 'carte-test' };
        case 'positioning_sent':
            return { label: 'Noter la réception du test', anchor: 'carte-test' };
        case 'positioning_received':
            return { label: "Planifier l'entretien", anchor: 'carte-entretien' };
        case 'interview':
            return c.pathway === 'alternance' && !c.alternance
                ? { label: "Saisir le contrat d'alternance", anchor: 'carte-alternance' }
                : { label: "Passer à l'étape « Admis »", anchor: 'carte-dossier' };
        case 'admitted':
            return { label: 'Créer le dossier de formation', anchor: 'carte-dossier' };
        default:
            return null;
    }
}

function go(anchor: string) {
    document.getElementById(anchor)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/** Where the candidate stands on the path, and what comes next. */
export function CandidatureSteps({ c, onCreateDossier }: { c: Candidature; onCreateDossier?: () => void }) {
    const exited = STAGE_RANK[c.status] < 0;
    const current = c.dossier && !c.dossier.trashed ? 5 : STAGE_RANK[c.status];
    const next = exited ? null : nextAction(c);

    return (
        <nav
            aria-label="Étapes de la candidature"
            className="flex flex-col gap-3 rounded-xl border border-green-200 bg-green-50 p-3 dark:border-green-900 dark:bg-green-950/40 lg:flex-row lg:items-center lg:justify-between"
        >
            <ol className="flex flex-wrap items-center gap-1 text-sm">
                {STEPS.map((step, i) => {
                    const done = !exited && i < current;
                    const isCurrent = !exited && i === current;
                    return (
                        <li key={step.key} className="flex items-center gap-1">
                            {i > 0 && <span aria-hidden className={cn('h-px w-4', done || isCurrent ? 'bg-primary' : 'bg-border')} />}
                            <button
                                type="button"
                                onClick={() => go(step.anchor)}
                                aria-current={isCurrent ? 'step' : undefined}
                                className={cn(
                                    'flex items-center gap-1 rounded-full border px-2.5 py-1 transition-colors',
                                    isCurrent && 'border-primary bg-primary text-primary-foreground font-medium',
                                    done && 'border-primary/40 text-foreground',
                                    !done && !isCurrent && 'text-muted-foreground',
                                )}
                            >
                                {done && <Check className="h-3.5 w-3.5" aria-hidden />}
                                {step.label}
                            </button>
                        </li>
                    );
                })}
                {exited && <li className="border-destructive text-destructive ml-2 rounded-full border px-2.5 py-1 font-medium">{c.status_label}</li>}
            </ol>
            {next && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="text-muted-foreground">Prochaine action :</span>
                    {next.anchor === 'carte-dossier' && c.status === 'admitted' && onCreateDossier ? (
                        <Button size="sm" onClick={onCreateDossier}>
                            {next.label}
                        </Button>
                    ) : (
                        <Button size="sm" variant="outline" onClick={() => go(next.anchor)}>
                            {next.label}
                        </Button>
                    )}
                </div>
            )}
        </nav>
    );
}
