import { PLACE_LABEL, WEEKS, textareaClass, type Place, type Weeks } from '@/components/admissions/booklet-types';
import AppLogoIcon from '@/components/app-logo-icon';
import InputError from '@/components/input-error';
import SignaturePad from '@/components/signature-pad';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { sendJson } from '@/lib/sessions';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, LoaderCircle, PenLine } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface PublicPage {
    place: Place;
    entries: Weeks;
    observations: Weeks | null;
}

interface GridType {
    title: string;
    competences: { title: string; skills: { key: string; title: string; level: string | null }[] }[];
}

interface Props {
    state: 'open' | 'invalid';
    signer?: 'apprentice' | 'tutor';
    month_label?: string;
    organisme?: string;
    apprentice?: string;
    employer?: string | null;
    training?: string | null;
    expires_on?: string;
    pages?: PublicPage[];
    grid?: GridType[] | null;
    save_url?: string;
    code_url?: string;
    sign_url?: string;
}

const LEVELS = ['A', 'ECA', 'NA'] as const;

function Shell({ title, subtitle, children }: { title: string; subtitle?: string; children: React.ReactNode }) {
    return (
        <div className="bg-background min-h-svh px-4 py-6">
            <Head title={title} />
            <main className="mx-auto flex w-full max-w-3xl flex-col gap-6">
                <header className="flex items-center gap-3">
                    <AppLogoIcon className="size-8 fill-current" />
                    <div>
                        <h1 className="text-xl font-semibold">{title}</h1>
                        {subtitle && <p className="text-muted-foreground text-sm">{subtitle}</p>}
                    </div>
                </header>
                {children}
            </main>
        </div>
    );
}

export default function PublicBooklet(props: Props) {
    if (props.state !== 'open') {
        return (
            <Shell title="Livret d'apprentissage">
                <p className="rounded-md border p-4 text-sm">Ce lien n'est pas valable : il a expiré, la page a déjà été signée ou le lien a été renouvelé.</p>
            </Shell>
        );
    }
    return <OpenBooklet {...props} />;
}

function OpenBooklet({ signer, month_label, organisme, apprentice, employer, training, expires_on, pages = [], grid, save_url, code_url, sign_url }: Props) {
    const isTutor = signer === 'tutor';
    const [entries, setEntries] = useState<Record<string, Weeks>>(Object.fromEntries(pages.map((p) => [p.place, p.entries])));
    const [observations, setObservations] = useState<Weeks | null>(pages[0]?.observations ?? null);
    const [marks, setMarks] = useState<Record<string, string | null>>(
        Object.fromEntries((grid ?? []).flatMap((t) => t.competences.flatMap((c) => c.skills.map((s) => [s.key, s.level])))),
    );
    const [dirty, setDirty] = useState<Record<string, unknown> | null>(null);
    const [saved, setSaved] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
    const [codeSent, setCodeSent] = useState(false);
    const [code, setCode] = useState('');
    const [drawing, setDrawing] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string | undefined>>({});
    const [done, setDone] = useState(false);
    const pending = useRef<Record<string, unknown> | null>(null);
    pending.current = dirty;

    const save = async (): Promise<boolean> => {
        const body = pending.current;
        if (!body || !save_url) return true;
        setDirty(null);
        setSaved('saving');
        const answer = await sendJson('POST', save_url, body);
        if (answer.status === 404) {
            router.reload();
            return false;
        }
        setSaved(answer.ok ? 'saved' : 'error');
        if (!answer.ok) setErrors({ ...answer.errors, general: answer.message ?? undefined });

        return answer.ok;
    };

    // Saved as typed: every 10 seconds when something changed, and on leaving a field.
    useEffect(() => {
        const timer = window.setInterval(() => void save(), 10000);
        return () => window.clearInterval(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const merge = (patch: Record<string, unknown>) => {
        const next: Record<string, unknown> = { ...(pending.current ?? {}) };
        for (const [key, value] of Object.entries(patch)) {
            const current = next[key];
            next[key] =
                key === 'marks'
                    ? [...((current as unknown[]) ?? []), ...(value as unknown[])]
                    : key === 'entries'
                      ? Object.fromEntries(
                            Object.entries({ ...((current as Record<string, Weeks>) ?? {}) }).concat(
                                Object.entries(value as Record<string, Weeks>).map(([place, weeks]) => [
                                    place,
                                    { ...(((current as Record<string, Weeks>) ?? {})[place] ?? {}), ...weeks },
                                ]),
                            ),
                        )
                      : { ...((current as object) ?? {}), ...(value as object) };
        }
        setDirty(next);
        pending.current = next;
        setSaved('idle');
    };

    const askCode = async () => {
        if (!code_url) return;
        setBusy(true);
        if (!(await save())) return setBusy(false);
        const answer = await sendJson('POST', code_url);
        setBusy(false);
        if (answer.ok) {
            setCodeSent(true);
            setErrors({});
        } else if (answer.status === 404) router.reload();
        else setErrors({ general: answer.message ?? undefined });
    };

    const sign = async () => {
        if (!sign_url) return;
        setBusy(true);
        if (!(await save())) return setBusy(false);
        const answer = await sendJson('POST', sign_url, { code, drawing });
        setBusy(false);
        if (answer.ok) setDone(true);
        else if (answer.status === 404) router.reload();
        else
            setErrors({
                code: answer.errors.code,
                drawing: answer.errors.drawing,
                general: answer.errors.code || answer.errors.drawing ? undefined : (answer.message ?? undefined),
            });
    };

    const title = `Livret d'apprentissage - ${month_label}`;
    const subtitle = [organisme, isTutor ? `apprenti : ${apprentice}` : apprentice, employer, training].filter(Boolean).join(' · ');

    if (done) {
        return (
            <Shell title={title} subtitle={subtitle}>
                <p className="flex items-center gap-2 rounded-md border p-4 text-sm" role="status">
                    <CheckCircle2 className="h-5 w-5 text-green-600" />
                    Merci, votre page de {month_label?.toLowerCase()} est signée.
                </p>
            </Shell>
        );
    }

    return (
        <Shell title={title} subtitle={subtitle}>
            <p className="text-sm">
                {isTutor
                    ? "Lisez les travaux de l'apprenti, écrivez vos observations semaine par semaine, évaluez ses compétences en entreprise, puis signez."
                    : 'Décrivez les travaux effectués chaque semaine : activités, travaux réalisés, responsabilités confiées, initiatives prises. Puis signez.'}{' '}
                Ce lien est valable jusqu'au {expires_on}. Ce que vous écrivez est enregistré au fur et à mesure.
            </p>

            {pages.map((page) => (
                <section key={page.place} className="flex flex-col gap-3 rounded-md border p-4">
                    <h2 className="font-semibold">Travaux effectués {PLACE_LABEL[page.place].toLowerCase()}</h2>
                    {WEEKS.map((w) => (
                        <div key={w.key} className="grid gap-2">
                            {isTutor ? (
                                <>
                                    <p className="text-sm">
                                        <span className="font-medium">{w.label} - apprenti : </span>
                                        <span className="whitespace-pre-line">{page.entries[w.key] ?? <span className="text-muted-foreground">rien de saisi</span>}</span>
                                    </p>
                                    <Label htmlFor={`obs-${w.key}`}>Vos observations - {w.label}</Label>
                                    <textarea
                                        id={`obs-${w.key}`}
                                        rows={3}
                                        maxLength={2000}
                                        className={textareaClass}
                                        value={observations?.[w.key] ?? ''}
                                        onChange={(e) => {
                                            setObservations({ ...(observations as Weeks), [w.key]: e.target.value });
                                            merge({ observations: { [w.key]: e.target.value } });
                                        }}
                                        onBlur={() => void save()}
                                    />
                                    <InputError message={errors[`observations.${w.key}`]} />
                                </>
                            ) : (
                                <>
                                    <Label htmlFor={`${page.place}-${w.key}`}>{w.label}</Label>
                                    <textarea
                                        id={`${page.place}-${w.key}`}
                                        rows={3}
                                        maxLength={2000}
                                        className={textareaClass}
                                        value={entries[page.place]?.[w.key] ?? ''}
                                        onChange={(e) => {
                                            setEntries({ ...entries, [page.place]: { ...entries[page.place], [w.key]: e.target.value } });
                                            merge({ entries: { [page.place]: { [w.key]: e.target.value } } });
                                        }}
                                        onBlur={() => void save()}
                                    />
                                    <InputError message={errors[`entries.${page.place}.${w.key}`]} />
                                </>
                            )}
                        </div>
                    ))}
                </section>
            ))}

            {isTutor && grid && grid.length > 0 && (
                <section className="flex flex-col gap-3 rounded-md border p-4">
                    <h2 className="font-semibold">Compétences en entreprise</h2>
                    <p className="text-muted-foreground text-sm">A : acquis - ECA : en cours d'acquisition - NA : non acquis.</p>
                    {grid.map((t, i) => (
                        <div key={i} className="flex flex-col gap-2">
                            <h3 className="text-sm font-semibold">
                                Activité type {i + 1} - {t.title}
                            </h3>
                            {t.competences.map((c, j) => (
                                <div key={j} className="flex flex-col gap-2 pl-2">
                                    <p className="text-sm italic">{c.title}</p>
                                    {c.skills.map((s) => (
                                        <div key={s.key} className="flex flex-wrap items-center justify-between gap-2 pl-2 text-sm">
                                            <span>{s.title}</span>
                                            <span className="flex gap-1" role="group" aria-label={s.title}>
                                                {LEVELS.map((level) => (
                                                    <Button
                                                        key={level}
                                                        type="button"
                                                        size="sm"
                                                        variant={marks[s.key] === level ? 'default' : 'outline'}
                                                        aria-pressed={marks[s.key] === level}
                                                        onClick={() => {
                                                            const value = marks[s.key] === level ? null : level;
                                                            setMarks({ ...marks, [s.key]: value });
                                                            merge({ marks: [{ key: s.key, level: value ?? '' }] });
                                                        }}
                                                    >
                                                        {level}
                                                    </Button>
                                                ))}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                    ))}
                </section>
            )}

            <p className="text-muted-foreground text-xs" aria-live="polite">
                {saved === 'saving' ? 'Enregistrement…' : saved === 'saved' ? 'Enregistré.' : saved === 'error' ? "L'enregistrement a échoué." : ''}
            </p>

            <section className="flex flex-col gap-4 rounded-md border p-4">
                <h2 className="font-semibold">Signer</h2>
                <SignaturePad id="drawing" onChange={setDrawing} invalid={!!errors.drawing} />
                <InputError message={errors.drawing} />
                <div className="grid gap-1">
                    <Button variant="outline" className="justify-self-start" disabled={busy} onClick={askCode}>
                        {codeSent ? 'Recevoir un nouveau code' : 'Recevoir mon code'}
                    </Button>
                    {codeSent && <p className="text-muted-foreground text-xs">Un code à 6 chiffres vous a été envoyé par e-mail (valable 10 minutes).</p>}
                </div>
                <div className="grid max-w-48 gap-1">
                    <Label htmlFor="code">Code reçu par e-mail</Label>
                    <Input id="code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={(e) => setCode(e.target.value)} />
                    <InputError message={errors.code} />
                </div>
                <InputError message={errors.general} />
                <Button className="justify-self-start" disabled={busy || drawing === '' || code.trim().length !== 6} onClick={sign}>
                    {busy ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <PenLine className="h-4 w-4" />}
                    Signer la page de {month_label?.toLowerCase()}
                </Button>
            </section>
        </Shell>
    );
}
