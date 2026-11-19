import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Question } from '@/types/online-tests';
import { AlertTriangle, LoaderCircle, Monitor } from 'lucide-react';
import { type ClipboardEvent, type DragEvent, type MouseEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react';

export interface OnlineProps {
    title: string;
    time_limit_minutes: number | null;
    sections: { title: string; intro?: string | null; questions: Question[] }[];
    attempt: { answers: Record<string, unknown>; remaining_seconds: number | null; submitted: boolean } | null;
}

type Answers = Record<string, unknown>;

const DESKTOP = '(min-width: 1024px) and (pointer: fine)';
const textarea = 'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden';

function xsrf(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
}

async function call(url: string, method: string, body?: unknown): Promise<{ ok: boolean; status: number; json: Record<string, unknown> }> {
    const response = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const json = (await response.json().catch(() => ({}))) as Record<string, unknown>;

    return { ok: response.ok, status: response.status, json };
}

function clock(seconds: number): string {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;

    return `${m} min ${String(s).padStart(2, '0')} s`;
}

/** One question as the candidate sees it (never the right answer). */
function Field({ q, value, onChange }: { q: Question; value: unknown; onChange: (v: unknown) => void }) {
    const common = { autoComplete: 'off', spellCheck: false, 'data-gramm': 'false' } as const;
    switch (q.type) {
        case 'short_text':
            return <Input {...common} value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)} aria-label={q.label} />;
        case 'long_text':
            return (
                <textarea
                    {...common}
                    className={textarea}
                    rows={8}
                    value={(value as string) ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                    aria-label={q.label}
                />
            );
        case 'number':
            return (
                <Input
                    {...common}
                    inputMode="decimal"
                    className="w-48"
                    value={(value as string) ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                    aria-label={q.label}
                />
            );
        case 'true_false':
        case 'yes_no': {
            const [yes, no] = q.type === 'true_false' ? ['Vrai', 'Faux'] : ['Oui', 'Non'];
            return (
                <div className="flex gap-6">
                    {[
                        { v: true, l: yes },
                        { v: false, l: no },
                    ].map((o) => (
                        <Label key={o.l} className="flex items-center gap-2 font-normal">
                            <input type="radio" name={q.id} checked={value === o.v} onChange={() => onChange(o.v)} />
                            {o.l}
                        </Label>
                    ))}
                </div>
            );
        }
        case 'single_choice':
            return (
                <div className="grid gap-1">
                    {(q.choices ?? []).map((c) => (
                        <Label key={c.id} className="flex items-center gap-2 font-normal">
                            <input type="radio" name={q.id} checked={value === c.id} onChange={() => onChange(c.id)} />
                            {c.label}
                        </Label>
                    ))}
                </div>
            );
        case 'multiple_choice': {
            const list = Array.isArray(value) ? (value as string[]) : [];
            return (
                <div className="grid gap-1">
                    {(q.choices ?? []).map((c) => (
                        <Label key={c.id} className="flex items-center gap-2 font-normal">
                            <input
                                type="checkbox"
                                checked={list.includes(c.id)}
                                onChange={(e) => onChange(e.target.checked ? [...list, c.id] : list.filter((x) => x !== c.id))}
                            />
                            {c.label}
                        </Label>
                    ))}
                </div>
            );
        }
        default:
            return null;
    }
}

/**
 * The online positioning test on the candidate's page: desktop only, one
 * section at a time, answers saved on the server as typed (1 s after the
 * last key), timer, automatic submission at the limit. Pasting, copying,
 * the context menu and dragging are blocked and recorded, as are exits from
 * the page.
 */
export function OnlineTestRunner({ token, online, submittedOn }: { token: string; online: OnlineProps; submittedOn?: string | null }) {
    const base = `/test-positionnement/${token}`;
    const [desktop, setDesktop] = useState(() => typeof window === 'undefined' || window.matchMedia(DESKTOP).matches);
    const [started, setStarted] = useState(online.attempt !== null && !online.attempt.submitted);
    const [done, setDone] = useState(online.attempt?.submitted ?? false);
    const [answers, setAnswers] = useState<Answers>(online.attempt?.answers ?? {});
    const [remaining, setRemaining] = useState<number | null>(online.attempt?.remaining_seconds ?? null);
    const [section, setSection] = useState(0);
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const pending = useRef<Answers>({});
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        const query = window.matchMedia(DESKTOP);
        const listener = () => setDesktop(query.matches);
        query.addEventListener('change', listener);
        return () => query.removeEventListener('change', listener);
    }, []);

    const event = useCallback((type: string) => void call(`${base}/events`, 'POST', { type }), [base]);

    const flush = useCallback(async () => {
        const batch = pending.current;
        pending.current = {};
        if (Object.keys(batch).length === 0) return true;
        const result = await call(`${base}/answers`, 'PUT', { answers: batch });
        if (result.status === 409) {
            setDone(true);
            setStarted(false);
            setMessage(String(result.json.message ?? 'Vos réponses ont été envoyées.'));
            return false;
        }
        if (!result.ok) setMessage('Une réponse n’a pas pu être enregistrée : vérifiez votre connexion.');
        if (typeof result.json.remaining_seconds === 'number') setRemaining(result.json.remaining_seconds);
        return result.ok;
    }, [base]);

    const answer = (id: string, value: unknown) => {
        setAnswers((a) => ({ ...a, [id]: value }));
        pending.current[id] = value;
        if (timer.current) clearTimeout(timer.current);
        timer.current = setTimeout(() => void flush(), 1000);
    };

    const submit = useCallback(
        async (auto = false) => {
            if (!auto && !window.confirm('Envoyer vos réponses ? Vous ne pourrez plus les modifier.')) return;
            setBusy(true);
            if (timer.current) clearTimeout(timer.current);
            const saved = await flush();
            if (saved) {
                const result = await call(`${base}/submit`, 'POST');
                setMessage(String(result.json.status ?? result.json.message ?? ''));
            }
            setBusy(false);
            setDone(true);
            setStarted(false);
        },
        [base, flush],
    );

    // Exits from the page while the test runs.
    useEffect(() => {
        if (!started) return;
        const onVisibility = () => document.visibilityState === 'hidden' && event('blur');
        const onBlur = () => event('blur');
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('blur', onBlur);
        return () => {
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('blur', onBlur);
        };
    }, [started, event]);

    // The clock; at zero the answers go.
    useEffect(() => {
        if (!started || remaining === null) return;
        if (remaining <= 0) {
            void submit(true);
            return;
        }
        const tick = setTimeout(() => setRemaining((r) => (r === null ? r : r - 1)), 1000);
        return () => clearTimeout(tick);
    }, [started, remaining, submit]);

    const block = {
        onPaste: (e: ClipboardEvent) => {
            e.preventDefault();
            event('paste_blocked');
            setMessage('Le collage est désactivé pendant le test.');
        },
        onCopy: (e: ClipboardEvent) => {
            e.preventDefault();
            event('copy_blocked');
        },
        onCut: (e: ClipboardEvent) => e.preventDefault(),
        onContextMenu: (e: MouseEvent) => e.preventDefault(),
        onDragStart: (e: DragEvent) => e.preventDefault(),
        onDrop: (e: DragEvent) => e.preventDefault(),
    };

    const questionCount = useMemo(() => online.sections.reduce((n, s) => n + s.questions.length, 0), [online.sections]);

    if (done) {
        return (
            <p className="rounded-md border p-3 text-sm">
                {message ?? `Vos réponses ont été envoyées${submittedOn ? ` le ${submittedOn}` : ''}.`} L’organisme reviendra vers vous ; vous n’avez
                rien d’autre à faire.
            </p>
        );
    }

    if (!desktop) {
        return (
            <div className="flex gap-3 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                <Monitor className="h-5 w-5 shrink-0" />
                <p>
                    <strong>Ouvrez ce lien sur un ordinateur.</strong> Ce test se passe sur un ordinateur (écran large, souris), pas sur un téléphone
                    ni une tablette.
                </p>
            </div>
        );
    }

    if (!started) {
        return (
            <div className="grid gap-4 text-sm">
                <p>
                    <strong>{online.title}</strong> : {questionCount} question{questionCount > 1 ? 's' : ''} en {online.sections.length} partie
                    {online.sections.length > 1 ? 's' : ''},{' '}
                    {online.time_limit_minutes ? `${online.time_limit_minutes} minutes` : 'sans limite de temps'}.
                </p>
                <ul className="list-disc space-y-1 pl-5">
                    <li>Vos réponses sont enregistrées au fur et à mesure : en cas de coupure, rouvrez ce lien pour continuer.</li>
                    <li>Le copier-coller est désactivé ; les sorties de la page sont relevées.</li>
                    {online.time_limit_minutes && (
                        <li>Le chronomètre démarre quand vous cliquez sur « Commencer » ; à la fin du temps, vos réponses sont envoyées.</li>
                    )}
                </ul>
                <div>
                    <Button
                        type="button"
                        disabled={busy}
                        onClick={async () => {
                            setBusy(true);
                            const result = await call(`${base}/start`, 'POST');
                            setBusy(false);
                            if (!result.ok) {
                                setMessage(String(result.json.message ?? 'Le test ne peut pas démarrer.'));
                                return;
                            }
                            setAnswers((result.json.answers as Answers) ?? {});
                            setRemaining((result.json.remaining_seconds as number | null) ?? null);
                            setStarted(true);
                        }}
                    >
                        {busy && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Commencer
                    </Button>
                </div>
                {message && <p className="text-red-600">{message}</p>}
            </div>
        );
    }

    const current = online.sections[section];

    return (
        <div className="grid gap-4" {...block}>
            <div className="bg-background sticky top-0 z-10 flex flex-wrap items-center justify-between gap-2 border-b py-2 text-sm">
                <span>
                    Partie {section + 1} / {online.sections.length} : <strong>{current.title}</strong>
                </span>
                {remaining !== null && (
                    <span className={remaining < 300 ? 'font-semibold text-red-600' : ''} aria-live="polite">
                        Temps restant : {clock(remaining)}
                    </span>
                )}
            </div>
            {message && (
                <p className="flex items-center gap-2 text-sm text-amber-700">
                    <AlertTriangle className="h-4 w-4" />
                    {message}
                </p>
            )}
            {current.intro && <p className="text-sm whitespace-pre-line select-none">{current.intro}</p>}
            {current.questions.map((q, i) => (
                <section key={q.id} className="grid gap-2 rounded-lg border p-3" aria-label={`Question ${i + 1}`}>
                    <p className="font-medium select-none">
                        {i + 1}. {q.label}
                        {q.required && ' *'}
                    </p>
                    {q.support_text && <div className="bg-muted/40 rounded-md p-3 text-sm whitespace-pre-line select-none">{q.support_text}</div>}
                    {q.image && <img src={q.image} alt="" className="max-h-80 w-auto select-none" draggable={false} />}
                    <Field q={q} value={answers[q.id]} onChange={(v) => answer(q.id, v)} />
                </section>
            ))}
            <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline" disabled={section === 0} onClick={() => setSection((s) => s - 1)}>
                    Partie précédente
                </Button>
                {section < online.sections.length - 1 ? (
                    <Button type="button" onClick={() => (void flush(), setSection((s) => s + 1))}>
                        Partie suivante
                    </Button>
                ) : (
                    <Button type="button" onClick={() => void submit()} disabled={busy}>
                        {busy && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Envoyer mes réponses
                    </Button>
                )}
            </div>
        </div>
    );
}
