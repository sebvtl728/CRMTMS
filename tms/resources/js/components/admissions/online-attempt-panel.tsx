import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/crm';
import { router, usePage } from '@inertiajs/react';
import { Download, Eye, PenLine } from 'lucide-react';
import { useState } from 'react';

export interface OnlineAttemptView {
    id: string;
    title: string;
    version: number;
    started_at: string;
    submitted_at: string | null;
    minutes: number | null;
    auto_score: number | null;
    score: number | null;
    max_score: number;
    exits: number;
    blocked_pastes: number;
    pending: string[];
    can_grade: boolean;
    sections: {
        title: string;
        questions: {
            id: string;
            label: string;
            type: string;
            points: number;
            given: string | null;
            right: string | null;
            earned: number | null;
            manual: boolean;
        }[];
    }[];
}

/**
 * The candidate's online test on the candidature, kept short: score, dates,
 * traces; « Aperçu du PDF » opens the copy in a new tab, then « Télécharger »
 * appears; « Corriger » opens the hand grading of the open questions.
 */
export function OnlineAttemptPanel({ candidatureId, attempt }: { candidatureId: string; attempt: OnlineAttemptView }) {
    const [previewed, setPreviewed] = useState(false);
    const [grading, setGrading] = useState(false);
    const pdf = `/admissions/candidatures/${candidatureId}/online-attempts/${attempt.id}/pdf`;
    const manual = attempt.sections.flatMap((s) => s.questions.filter((q) => q.manual));

    if (attempt.submitted_at === null) {
        return (
            <p className="rounded-md border p-2 text-sm">
                Test en ligne « {attempt.title} » commencé le {formatDate(attempt.started_at)} : en cours.
            </p>
        );
    }

    return (
        <div className="grid gap-2 rounded-md border p-3 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <strong>
                    {attempt.title} (version {attempt.version})
                </strong>
                <span className="text-lg font-semibold">
                    {attempt.score !== null
                        ? `${attempt.score} / ${attempt.max_score}`
                        : `${attempt.auto_score ?? 0} / ${attempt.max_score} (à compléter)`}
                </span>
            </div>
            <p className="text-muted-foreground text-xs">
                Envoyé le {formatDate(attempt.submitted_at)} · {attempt.minutes} min ·{' '}
                <span className={attempt.exits > 0 ? 'font-medium text-amber-700' : ''}>
                    {attempt.exits} sortie{attempt.exits > 1 ? 's' : ''} de la page
                </span>{' '}
                · {attempt.blocked_pastes} collage{attempt.blocked_pastes > 1 ? 's' : ''} bloqué{attempt.blocked_pastes > 1 ? 's' : ''}
            </p>
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => (window.open(`${pdf}?apercu=1`, '_blank', 'noopener'), setPreviewed(true))}
                >
                    <Eye className="h-4 w-4" />
                    Aperçu du PDF
                </Button>
                {previewed && (
                    <Button asChild size="sm" variant="outline">
                        <a href={pdf}>
                            <Download className="h-4 w-4" />
                            Télécharger
                        </a>
                    </Button>
                )}
                {attempt.can_grade && manual.length > 0 && (
                    <Button type="button" size="sm" onClick={() => setGrading(true)}>
                        <PenLine className="h-4 w-4" />
                        {attempt.pending.length > 0 ? `Corriger (${attempt.pending.length} à noter)` : 'Revoir la correction'}
                    </Button>
                )}
            </div>
            {grading && <GradeDialog candidatureId={candidatureId} attempt={attempt} questions={manual} onClose={() => setGrading(false)} />}
        </div>
    );
}

/** The open questions only: the candidate's answer and the score (0 to the question's points). */
function GradeDialog({
    candidatureId,
    attempt,
    questions,
    onClose,
}: {
    candidatureId: string;
    attempt: OnlineAttemptView;
    questions: OnlineAttemptView['sections'][number]['questions'];
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [scores, setScores] = useState<Record<string, string>>(() =>
        Object.fromEntries(questions.filter((q) => q.earned !== null).map((q) => [q.id, String(q.earned)])),
    );
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        router.put(
            `/admissions/candidatures/${candidatureId}/online-attempts/${attempt.id}/grades`,
            {
                scores: Object.fromEntries(
                    Object.entries(scores)
                        .filter(([, v]) => v !== '')
                        .map(([k, v]) => [k, Number(v)]),
                ),
            },
            { preserveScroll: true, onSuccess: () => onClose(), onFinish: () => setSaving(false) },
        );
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Corriger les réponses rédigées</DialogTitle>
                    <DialogDescription>
                        Notez chaque réponse de 0 au barème de la question ; la note finale se calcule quand tout est noté.
                    </DialogDescription>
                </DialogHeader>
                <ul className="grid gap-3 text-sm">
                    {questions.map((q) => (
                        <li key={q.id} className="grid gap-1 rounded border p-2">
                            <span className="font-medium">{q.label}</span>
                            <span className="bg-muted/40 rounded p-2 whitespace-pre-line">
                                {q.given ?? <em className="text-muted-foreground">sans réponse</em>}
                            </span>
                            <span className="flex items-center gap-2 text-xs">
                                Note
                                <Input
                                    type="number"
                                    min={0}
                                    max={q.points}
                                    className="h-7 w-20"
                                    aria-label={`Note de « ${q.label} »`}
                                    value={scores[q.id] ?? ''}
                                    onChange={(e) => setScores({ ...scores, [q.id]: e.target.value })}
                                />
                                / {q.points}
                            </span>
                            <InputError message={errors[`scores.${q.id}`]} />
                        </li>
                    ))}
                </ul>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button type="button" onClick={save} disabled={saving}>
                        Enregistrer la correction
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
