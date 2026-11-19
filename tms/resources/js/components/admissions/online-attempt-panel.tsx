import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/crm';
import { router, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
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
 * The candidate's online test on the candidature: score, time, traces, each
 * answer next to the right one, and the hand grading of the open questions.
 */
export function OnlineAttemptPanel({ candidatureId, attempt }: { candidatureId: string; attempt: OnlineAttemptView }) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [scores, setScores] = useState<Record<string, string>>(() => {
        const init: Record<string, string> = {};
        attempt.sections.forEach((s) => s.questions.forEach((q) => q.manual && q.earned !== null && (init[q.id] = String(q.earned))));
        return init;
    });

    const save = () =>
        router.put(
            `/admissions/candidatures/${candidatureId}/online-attempts/${attempt.id}/grades`,
            {
                scores: Object.fromEntries(
                    Object.entries(scores)
                        .filter(([, v]) => v !== '')
                        .map(([k, v]) => [k, Number(v)]),
                ),
            },
            { preserveScroll: true },
        );

    if (attempt.submitted_at === null) {
        return (
            <p className="rounded-md border p-2 text-sm">
                Test en ligne « {attempt.title} » commencé le {formatDate(attempt.started_at)} : en cours.
            </p>
        );
    }

    return (
        <div className="grid gap-3 rounded-md border p-3 text-sm">
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
            <div>
                <Button asChild type="button" size="sm" variant="outline">
                    <a href={`/admissions/candidatures/${candidatureId}/online-attempts/${attempt.id}/pdf`}>
                        <Download className="h-4 w-4" />
                        Télécharger le PDF
                    </a>
                </Button>
            </div>
            <p className="text-muted-foreground text-xs">
                Envoyé le {formatDate(attempt.submitted_at)} · {attempt.minutes} min ·{' '}
                <span className={attempt.exits > 0 ? 'font-medium text-amber-700' : ''}>
                    {attempt.exits} sortie{attempt.exits > 1 ? 's' : ''} de la page
                </span>{' '}
                · {attempt.blocked_pastes} collage{attempt.blocked_pastes > 1 ? 's' : ''} bloqué{attempt.blocked_pastes > 1 ? 's' : ''}
            </p>
            {attempt.sections.map((section) => (
                <div key={section.title} className="grid gap-1">
                    <p className="font-medium">{section.title}</p>
                    <ul className="grid gap-2">
                        {section.questions.map((q) => (
                            <li key={q.id} className="grid gap-1 rounded border p-2">
                                <span>{q.label}</span>
                                <span className="whitespace-pre-line">
                                    <span className="text-muted-foreground text-xs">Réponse : </span>
                                    {q.given ?? <em className="text-muted-foreground">sans réponse</em>}
                                </span>
                                {q.right !== null && (
                                    <span className="text-xs">
                                        Attendu : {q.right} · {q.earned} / {q.points}
                                    </span>
                                )}
                                {q.manual && (
                                    <span className="flex items-center gap-2 text-xs">
                                        Note
                                        <Input
                                            type="number"
                                            min={0}
                                            max={q.points}
                                            className="h-7 w-20"
                                            aria-label={`Note de « ${q.label} »`}
                                            disabled={!attempt.can_grade}
                                            value={scores[q.id] ?? ''}
                                            onChange={(e) => setScores({ ...scores, [q.id]: e.target.value })}
                                        />
                                        / {q.points}
                                        {errors[`scores.${q.id}`] && <span className="text-red-600">{errors[`scores.${q.id}`]}</span>}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
            {attempt.can_grade && attempt.sections.some((s) => s.questions.some((q) => q.manual)) && (
                <div>
                    <Button type="button" size="sm" onClick={save}>
                        Enregistrer la correction
                    </Button>
                </div>
            )}
        </div>
    );
}
