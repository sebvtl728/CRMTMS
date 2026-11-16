import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { type Option } from '@/lib/crm';
import { LoaderCircle, Sparkles } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

/** Text fields of the sheet the AI may propose (form keys), in the order of the form. */
export const ASSISTANT_TEXTS = [
    ['title', 'Intitulé'],
    ['das_id', 'DAS'],
    ['duration_hours', 'Durée (heures)'],
    ['duration_days', 'Durée (jours)'],
    ['description', 'Description courte'],
    ['audience', 'Public visé'],
    ['prerequisites', 'Prérequis'],
    ['methods', 'Méthodes et moyens pédagogiques'],
    ['evaluation', 'Modalités d’évaluation'],
    ['sanction', 'Sanction de la formation'],
    ['accessibility', 'Accessibilité'],
    ['access_terms', 'Modalités et délais d’accès'],
    ['results', 'Indicateurs de résultats'],
] as const;

export type AssistantText = (typeof ASSISTANT_TEXTS)[number][0];

export type ProposedModule = { title: string; duration_hours: string | null; modality_id: string | null; content: string | null };

type Proposal = Record<AssistantText, string | null> & { objectives: string[]; modules: ProposedModule[] };

export type CurrentModule = { title: string; duration_hours: string; modality_id: string; content: string };

/** What « Appliquer » changes: the kept fields only; a kept module replaces the current one at the same rank (at) or is added (at null). */
export type AssistantPatch = {
    values: Partial<Record<AssistantText, string>>;
    objectives?: string[];
    modules: { at: number | null; module: ProposedModule }[];
};

interface Props {
    configured: boolean;
    current: Record<AssistantText, string> & { objectives: string[]; modules: CurrentModule[] };
    options: { das: Option[]; modality: Option[] };
    onApply: (patch: AssistantPatch) => void;
    /** True while a proposal is shown: the form is then read-only, so the current values and the default boxes stay true. */
    onReviewChange?: (reviewing: boolean) => void;
}

const NOT_CONFIGURED = 'Configurez l’intelligence artificielle dans Administration → Connecteurs.';
const FAILED = 'L’assistant n’a pas pu répondre. Réessayez dans quelques instants.';
const MAX_PROMPT = 2000;
const textareaClass = 'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** The French message of a failed answer: the first validation error, else the server's message. */
async function failure(response: Response): Promise<string> {
    try {
        const body = (await response.json()) as { message?: string; errors?: Record<string, string[]> };
        const first = body.errors ? Object.values(body.errors)[0]?.[0] : undefined;

        return first ?? (response.status === 403 ? 'Vous n’avez pas le droit d’utiliser l’assistant.' : (body.message ?? FAILED));
    } catch {
        return FAILED;
    }
}

const empty = (value: string | null | undefined) => (value ?? '').trim() === '';

export default function TrainingAssistant({ configured, current, options, onApply, onReviewChange }: Props) {
    const [open, setOpen] = useState(false);
    const [source, setSource] = useState<'pdf' | 'prompt'>('pdf');
    const [file, setFile] = useState<File | null>(null);
    const [prompt, setPrompt] = useState('');
    const [waiting, setWaiting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [proposal, setProposal] = useState<Proposal | null>(null);
    const [truncated, setTruncated] = useState(false);
    const [keep, setKeep] = useState<Record<string, boolean>>({});
    const [applied, setApplied] = useState(false);

    useEffect(() => {
        onReviewChange?.(proposal !== null);
    }, [proposal, onReviewChange]);

    const label = (list: Option[], id: string | null | undefined) => (id ? (list.find((o) => o.value === id)?.label ?? '') : '');
    const shown = (key: AssistantText, value: string | null | undefined) => (key === 'das_id' ? label(options.das, value) : (value ?? ''));
    const moduleText = (m: { title: string; duration_hours: string | null; modality_id: string | null }) =>
        [m.title, m.duration_hours ? `${m.duration_hours} h` : null, label(options.modality, m.modality_id) || null].filter(Boolean).join(' · ');

    const fields = proposal ? ASSISTANT_TEXTS.filter(([key]) => !empty(proposal[key])) : [];
    const currentObjectives = current.objectives.filter((o) => !empty(o));

    const ask = async () => {
        setError(null);
        setApplied(false);
        if (source === 'pdf' && !file) {
            setError('Choisissez un fichier PDF.');
            return;
        }
        if (source === 'prompt' && prompt.trim() === '') {
            setError('Décrivez la formation souhaitée.');
            return;
        }

        setWaiting(true);
        try {
            const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' };
            let response: Response;
            if (source === 'pdf') {
                const body = new FormData();
                body.append('file', file as File);
                response = await fetch(route('catalogue.assistant.pdf'), { method: 'POST', credentials: 'same-origin', headers, body });
            } else {
                response = await fetch(route('catalogue.assistant.prompt'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { ...headers, 'Content-Type': 'application/json' },
                    body: JSON.stringify({ prompt }),
                });
            }
            if (!response.ok) {
                setError(await failure(response));
                return;
            }

            const result = (await response.json()) as { proposal: Proposal; truncated: boolean };
            // Kept by default where the form is empty, never where it is already filled.
            const defaults: Record<string, boolean> = {};
            for (const [key] of ASSISTANT_TEXTS) defaults[key] = empty(current[key]);
            defaults.objectives = currentObjectives.length === 0;
            result.proposal.modules.forEach((_, i) => (defaults[`module-${i}`] = i >= current.modules.length));
            setKeep(defaults);
            setTruncated(result.truncated);
            setProposal(result.proposal);
        } catch {
            setError(FAILED);
        } finally {
            setWaiting(false);
        }
    };

    const apply = () => {
        if (!proposal) return;
        const patch: AssistantPatch = { values: {}, modules: [] };
        for (const [key] of fields) {
            if (keep[key]) patch.values[key] = proposal[key] ?? '';
        }
        if (proposal.objectives.length > 0 && keep.objectives) patch.objectives = proposal.objectives;
        proposal.modules.forEach((module, i) => {
            if (keep[`module-${i}`]) patch.modules.push({ at: i < current.modules.length ? i : null, module });
        });
        onApply(patch);
        setProposal(null);
        setOpen(false);
        setApplied(true);
    };

    const keepBox = (key: string, name: string) => (
        <div className="flex items-center gap-2">
            <Checkbox
                id={`keep-${key}`}
                aria-label={`Garder la proposition : ${name}`}
                checked={keep[key] === true}
                onCheckedChange={(v) => setKeep({ ...keep, [key]: v === true })}
            />
            <Label htmlFor={`keep-${key}`} className="font-normal">
                Garder la proposition
            </Label>
        </div>
    );

    const row = (key: string, name: string, now: ReactNode, proposed: ReactNode, isEmpty: boolean) => (
        <div key={key} className="grid gap-2 rounded-lg border p-3 md:grid-cols-2" role="group" aria-label={name}>
            <h3 className="text-sm font-semibold md:col-span-2">{name}</h3>
            <div className="grid gap-1 text-sm">
                <span className="text-muted-foreground text-xs">Valeur actuelle</span>
                {isEmpty ? <span className="text-muted-foreground italic">(vide)</span> : <div className="whitespace-pre-line">{now}</div>}
            </div>
            <div className="grid gap-1 text-sm">
                <span className="text-muted-foreground text-xs">Proposition</span>
                <div className="whitespace-pre-line">{proposed}</div>
            </div>
            <div className="md:col-span-2">{keepBox(key, name)}</div>
        </div>
    );

    return (
        <section aria-label="Assistant IA" className="grid gap-3 rounded-xl border border-dashed p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                        <Sparkles className="h-4 w-4" /> Assistant IA
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        Une proposition depuis un programme PDF ou une demande écrite. Rien n’est enregistré : vous choisissez ce que vous gardez,
                        puis vous enregistrez la fiche.
                    </p>
                </div>
                {configured && !open && (
                    <Button type="button" variant="outline" onClick={() => setOpen(true)}>
                        <Sparkles className="h-4 w-4" /> Remplir avec l’IA
                    </Button>
                )}
            </div>

            {!configured && (
                <p role="note" className="text-sm">
                    {NOT_CONFIGURED}
                </p>
            )}

            {applied && (
                <p
                    role="status"
                    className="rounded-md border border-green-600/30 bg-green-50 p-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200"
                >
                    Proposition appliquée : relisez la fiche puis enregistrez.
                </p>
            )}

            {open && !proposal && (
                <div className="grid gap-3">
                    <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Source">
                        <Button
                            type="button"
                            role="radio"
                            aria-checked={source === 'pdf'}
                            variant={source === 'pdf' ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => setSource('pdf')}
                        >
                            Depuis un PDF
                        </Button>
                        <Button
                            type="button"
                            role="radio"
                            aria-checked={source === 'prompt'}
                            variant={source === 'prompt' ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => setSource('prompt')}
                        >
                            Depuis une demande
                        </Button>
                    </div>

                    {source === 'pdf' ? (
                        <div className="grid gap-2">
                            <Label htmlFor="assistant-file">Programme de la formation (PDF, 10 Mo au plus)</Label>
                            <input
                                id="assistant-file"
                                type="file"
                                accept="application/pdf,.pdf"
                                className="text-sm"
                                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                            />
                            <p className="text-muted-foreground text-xs">
                                Seul le texte du PDF est transmis à l’intelligence artificielle ; le fichier n’est pas conservé.
                            </p>
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <Label htmlFor="assistant-prompt">Votre demande</Label>
                            <textarea
                                id="assistant-prompt"
                                rows={4}
                                maxLength={MAX_PROMPT}
                                value={prompt}
                                onChange={(e) => setPrompt(e.target.value)}
                                className={textareaClass}
                                placeholder="Ex. Une formation de deux jours à l’accueil client pour des réceptionnistes d’hôtel."
                            />
                            <p className="text-muted-foreground text-xs">
                                {prompt.length} / {MAX_PROMPT.toLocaleString('fr-FR')} caractères
                            </p>
                        </div>
                    )}

                    {error && (
                        <p role="alert" className="text-sm text-red-600">
                            {error}
                        </p>
                    )}

                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" onClick={ask} disabled={waiting}>
                            {waiting && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Proposer
                        </Button>
                        <Button type="button" variant="outline" disabled={waiting} onClick={() => setOpen(false)}>
                            Fermer
                        </Button>
                        {waiting && (
                            <span role="status" className="text-muted-foreground text-sm">
                                L’intelligence artificielle prépare une proposition (jusqu’à deux minutes)…
                            </span>
                        )}
                    </div>
                </div>
            )}

            {proposal && (
                <div className="grid gap-3" role="region" aria-label="Proposition de l’IA">
                    <p className="text-muted-foreground text-sm">
                        La fiche est verrouillée pendant la relecture : appliquez ou annulez la proposition.
                    </p>
                    {truncated && (
                        <p role="note" className="rounded-md border border-amber-500 bg-amber-50 p-2 text-sm text-amber-900">
                            Le programme est long : seul son début a été analysé.
                        </p>
                    )}
                    {fields.length === 0 && proposal.objectives.length === 0 && proposal.modules.length === 0 && (
                        <p className="text-sm">L’intelligence artificielle n’a rien proposé.</p>
                    )}

                    {fields.map(([key, name]) =>
                        row(key, name, shown(key, current[key]), shown(key, proposal[key]), empty(shown(key, current[key]))),
                    )}

                    {proposal.objectives.length > 0 &&
                        row(
                            'objectives',
                            'Objectifs pédagogiques',
                            <ul className="list-disc pl-5">
                                {currentObjectives.map((o, i) => (
                                    <li key={i}>{o}</li>
                                ))}
                            </ul>,
                            <ul className="list-disc pl-5">
                                {proposal.objectives.map((o, i) => (
                                    <li key={i}>{o}</li>
                                ))}
                            </ul>,
                            currentObjectives.length === 0,
                        )}

                    {proposal.modules.map((m, i) => {
                        const now = current.modules[i];

                        return row(
                            `module-${i}`,
                            `Module ${i + 1}`,
                            now ? (
                                <>
                                    <span>{moduleText({ ...now, modality_id: now.modality_id || null })}</span>
                                    {now.content && <span className="text-muted-foreground block">{now.content}</span>}
                                </>
                            ) : null,
                            <>
                                <span>{moduleText(m)}</span>
                                {m.content && <span className="text-muted-foreground block">{m.content}</span>}
                            </>,
                            !now,
                        );
                    })}

                    <div className="flex flex-wrap gap-2">
                        <Button type="button" onClick={apply}>
                            Appliquer
                        </Button>
                        <Button type="button" variant="outline" onClick={() => setProposal(null)}>
                            Annuler
                        </Button>
                    </div>
                </div>
            )}
        </section>
    );
}
