import { Badge } from '@/components/ui/badge';
import { formatDay } from '@/lib/crm';
import { CheckCircle2, Circle, Hand } from 'lucide-react';
import { type ReactNode } from 'react';

/** The states of a dossier, in their order (the effective status). */
const ORDER = ['created', 'convention_sent', 'convention_signed', 'convocations_sent', 'running', 'done', 'invoiced', 'settled'];

export interface DossierStepsData {
    status: string;
    particulier: boolean;
    convention_sent_on: string | null;
    convention_signed_on: string | null;
    convocations_sent_on: string | null;
    automation: { convention_on_quote: boolean; convocation_days: number };
    /** The « Documents » block is on the page (link to it). */
    hasDocuments: boolean;
}

/**
 * « Étapes » of a dossier: each step, done or not, and HOW it moves: by
 * itself (badge « Automatique ») or by an action somewhere else on the page.
 * The manual corrections (a document sent outside the TMS) are kept apart,
 * folded, with a warning: nobody should click them by reflex.
 */
export function DossierSteps({ data, corrections }: { data: DossierStepsData; corrections: ReactNode }) {
    const rank = ORDER.indexOf(data.status);
    const reached = (state: string) => rank >= ORDER.indexOf(state);
    const doc = data.particulier ? 'Contrat' : 'Convention';
    const documents = data.hasDocuments ? (
        <a href="#documents" className="underline">
            bloc Documents
        </a>
    ) : (
        'bloc Documents'
    );

    const steps: { label: string; done: boolean; date?: string | null; how: ReactNode }[] = [
        {
            label: `${doc} envoyé${data.particulier ? '' : 'e'}`,
            done: data.convention_sent_on !== null || reached('convention_sent'),
            date: data.convention_sent_on,
            how: data.automation.convention_on_quote ? (
                <>Émise et envoyée en signature toute seule à la création du dossier depuis le devis signé.</>
            ) : (
                <>Se coche seule quand vous l'envoyez ou la faites signer depuis le {documents} (Émettre, puis Envoyer ou Demander la signature).</>
            ),
        },
        {
            label: `${doc} signé${data.particulier ? '' : 'e'}`,
            done: data.convention_signed_on !== null || reached('convention_signed'),
            date: data.convention_signed_on,
            how: <>Se coche seule quand le client signe en ligne.</>,
        },
        {
            label: 'Convocations envoyées',
            done: data.convocations_sent_on !== null || reached('convocations_sent'),
            date: data.convocations_sent_on,
            how:
                data.automation.convocation_days > 0 ? (
                    <>
                        Envoyées toutes seules {data.automation.convocation_days} jours avant le début (session confirmée), ou plus tôt depuis
                        « Documents des stagiaires » de la session.
                    </>
                ) : (
                    <>Se coche seule quand chaque stagiaire a reçu sa convocation (« Documents des stagiaires » de la session).</>
                ),
        },
        { label: 'En cours', done: reached('running'), how: <>Automatique : le premier jour de formation.</> },
        { label: 'Réalisé', done: reached('done'), how: <>Automatique : le lendemain du dernier jour de formation.</> },
        { label: 'Facturé', done: reached('invoiced'), how: <>Automatique : à la validation des factures (bloc Facturation).</> },
    ];
    const next = steps.findIndex((s) => !s.done);

    return (
        <section aria-label="Étapes" className="grid gap-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">Étapes</h2>
                <p className="text-muted-foreground text-xs">Toutes les étapes avancent seules : vous n'avez rien à cocher ici.</p>
            </div>
            <ol className="grid gap-2">
                {steps.map((s, i) => (
                    <li key={s.label} className={`flex gap-3 rounded-md p-2 text-sm ${i === next ? 'bg-muted' : ''}`}>
                        {s.done ? (
                            <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-green-600" aria-label="Fait" />
                        ) : (
                            <Circle className="text-muted-foreground mt-0.5 h-4 w-4 shrink-0" aria-label="À venir" />
                        )}
                        <div className="grid gap-0.5">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className={s.done ? 'font-medium' : ''}>{s.label}</span>
                                {s.done && s.date && <span className="text-muted-foreground text-xs">le {formatDay(s.date)}</span>}
                                {i === next && <Badge variant="secondary">Prochaine étape</Badge>}
                            </div>
                            <p className="text-muted-foreground text-xs">{s.how}</p>
                        </div>
                    </li>
                ))}
            </ol>
            {corrections && (
                <details className="rounded-md border border-dashed p-3 text-sm">
                    <summary className="flex cursor-pointer items-center gap-2 font-medium">
                        <Hand className="h-4 w-4" />
                        Corriger une étape à la main
                    </summary>
                    <p className="mt-2 text-xs text-amber-700 dark:text-amber-400">
                        Seulement si le document a été envoyé ou signé en dehors du TMS (papier, autre outil). Sinon ne cliquez pas : l'étape se
                        mettra à jour toute seule, et une étape marquée à la main ne se défait pas.
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2">{corrections}</div>
                </details>
            )}
        </section>
    );
}
