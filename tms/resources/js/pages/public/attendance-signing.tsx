import InputError from '@/components/input-error';
import SignaturePad from '@/components/signature-pad';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { sendJson } from '@/lib/sessions';
import { Head, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, LoaderCircle, PenLine } from 'lucide-react';
import { useState } from 'react';

interface Props {
    state: 'open' | 'invalid';
    organisme?: string;
    session?: string;
    date?: string;
    half_label?: string;
    trainees?: { id: string; label: string }[];
    /** Trainees expected on this half-day, signed or not. */
    expected?: number;
    post_url?: string;
    /** on_time, or a catch-up of a past half-day (on site: the same list; remote: one trainee, a code by e-mail). */
    mode?: 'on_time' | 'catch_up_onsite' | 'catch_up_remote';
    banner?: string | null;
    trainee_first_name?: string;
    code_url?: string;
    /** A grouped catch-up: every half-day the one drawing signs. */
    halves?: string[] | null;
}

/**
 * A half-day's signing page (no account): pick one's name, sign in the box,
 * « Je signe ma présence ». The same device may be passed round: after the
 * thank-you, the list comes back without the names already signed.
 */
export default function AttendanceSigning(props: Props) {
    if (props.state === 'open' && props.mode === 'catch_up_remote') return <RemoteCatchUp {...props} />;

    return <ClassSigning {...props} />;
}

/** The list of the half-day (on time, or the on-site catch-up of a past half-day). */
function ClassSigning({ state, organisme, session, date, half_label, trainees = [], expected = 0, post_url, banner }: Props) {
    const [chosen, setChosen] = useState<{ id: string; label: string } | null>(null);
    const [drawing, setDrawing] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [done, setDone] = useState<string | null>(null);

    if (state === 'invalid') {
        return (
            <AuthLayout title="Lien d'émargement invalide" description="Ce lien d'émargement n'est plus valide. Demandez au formateur.">
                <Head title="Émargement" />
            </AuthLayout>
        );
    }

    const submit = async () => {
        if (!chosen || !post_url) return;
        setBusy(true);
        const answer = await sendJson('POST', post_url, { enrolment_id: chosen.id, drawing });
        setBusy(false);
        if (answer.ok) {
            setDone(chosen.label);
            setChosen(null);
            setDrawing('');
            setError(null);
            window.setTimeout(() => {
                setDone(null);
                router.reload({ only: ['trainees', 'expected'] });
            }, 3000);
        } else {
            setError(answer.errors.drawing ?? answer.errors.enrolment_id ?? answer.message);
            if (answer.status === 409 || answer.status === 404) router.reload({ only: ['trainees', 'expected', 'state'] });
        }
    };

    return (
        <AuthLayout title={`Émargement – ${half_label ?? ''}`} description={`${organisme ?? ''} · ${session ?? ''} · ${date ?? ''}`}>
            <Head title="Émargement" />
            {banner && <p className="rounded-md border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900">{banner}</p>}
            {done ? (
                <p className="flex items-center gap-2 rounded-md border p-4 text-sm" role="status">
                    <CheckCircle2 className="h-5 w-5 text-green-600" />
                    Merci {done}, votre présence est enregistrée.
                </p>
            ) : chosen ? (
                <div className="grid gap-4">
                    <Button variant="ghost" size="sm" className="justify-self-start" onClick={() => setChosen(null)}>
                        <ArrowLeft className="h-4 w-4" />
                        Ce n'est pas moi
                    </Button>
                    <p className="text-sm">
                        <strong>{chosen.label}</strong>, signez dans le cadre avec le doigt, la souris ou un stylet.
                    </p>
                    <SignaturePad id="drawing" onChange={setDrawing} invalid={!!error} />
                    <InputError message={error ?? undefined} />
                    <Button disabled={busy || drawing === ''} onClick={submit}>
                        {busy ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <PenLine className="h-4 w-4" />}
                        Je signe ma présence
                    </Button>
                </div>
            ) : trainees.length === 0 && expected === 0 ? (
                <p className="text-sm">Aucun stagiaire n'est attendu à cette demi-journée. Prévenez le formateur.</p>
            ) : trainees.length === 0 ? (
                <p className="text-sm">Tout le monde a signé. Merci !</p>
            ) : (
                <div className="grid gap-2">
                    <p className="text-muted-foreground text-sm">Choisissez votre nom :</p>
                    {error && <InputError message={error} />}
                    <ul className="grid gap-2">
                        {trainees.map((t) => (
                            <li key={t.id}>
                                <Button variant="outline" className="h-12 w-full justify-start text-base" onClick={() => setChosen(t)}>
                                    {t.label}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </AuthLayout>
    );
}

/** The remote catch-up of one trainee: consent, a code by e-mail, the drawing. */
function RemoteCatchUp({ organisme, session, date, half_label, trainee_first_name, banner, code_url, post_url, halves }: Props) {
    const [consent, setConsent] = useState(false);
    const [codeSent, setCodeSent] = useState(false);
    const [code, setCode] = useState('');
    const [drawing, setDrawing] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<{ code?: string; drawing?: string; general?: string }>({});
    const [done, setDone] = useState(false);

    const askCode = async () => {
        if (!code_url) return;
        setBusy(true);
        const answer = await sendJson('POST', code_url);
        setBusy(false);
        if (answer.ok) {
            setCodeSent(true);
            setErrors({});
        } else {
            setErrors({ general: answer.message ?? undefined });
        }
    };
    const sign = async () => {
        if (!post_url) return;
        setBusy(true);
        const answer = await sendJson('POST', post_url, { code, drawing });
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

    return (
        <AuthLayout
            title={halves ? `Émargement – ${halves.length} demi-journées` : `Émargement – ${half_label ?? ''}`}
            description={`${organisme ?? ''} · ${session ?? ''}${halves ? '' : ` · ${date ?? ''}`}`}
        >
            <Head title="Émargement" />
            {done ? (
                <p className="flex items-center gap-2 rounded-md border p-4 text-sm" role="status">
                    <CheckCircle2 className="h-5 w-5 text-green-600" />
                    Merci {trainee_first_name}, votre signature est enregistrée. Elle sera confirmée par l'organisme.
                </p>
            ) : (
                <div className="grid gap-4">
                    {banner && <p className="rounded-md border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900">{banner}</p>}
                    {halves ? (
                        <div className="grid gap-1 text-sm">
                            <p>Bonjour {trainee_first_name}, signez une fois votre présence à ces demi-journées :</p>
                            <ul className="list-disc pl-5">
                                {halves.map((h) => (
                                    <li key={h}>{h}</li>
                                ))}
                            </ul>
                        </div>
                    ) : (
                        <p className="text-sm">
                            Bonjour {trainee_first_name}, signez votre présence du {date}, {half_label?.toLowerCase()}.
                        </p>
                    )}
                    <div className="flex items-start gap-2">
                        <Checkbox id="consent" checked={consent} onCheckedChange={(v) => setConsent(v === true)} />
                        <Label htmlFor="consent" className="text-sm leading-snug font-normal">
                            {halves
                                ? 'Je confirme avoir été présent(e) à chacune de ces demi-journées.'
                                : 'Je confirme avoir été présent(e) à cette demi-journée.'}
                        </Label>
                    </div>
                    <SignaturePad id="drawing" onChange={setDrawing} invalid={!!errors.drawing} />
                    <InputError message={errors.drawing} />
                    <div className="grid gap-1">
                        <Button variant="outline" className="justify-self-start" disabled={busy || !consent} onClick={askCode}>
                            {codeSent ? 'Recevoir un nouveau code' : 'Recevoir mon code'}
                        </Button>
                        {codeSent && (
                            <p className="text-muted-foreground text-xs">Un code à 6 chiffres vous a été envoyé par e-mail (valable 10 minutes).</p>
                        )}
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="code">Code reçu par e-mail</Label>
                        <Input
                            id="code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                        />
                        <InputError message={errors.code} />
                    </div>
                    <InputError message={errors.general} />
                    <Button disabled={busy || !consent || drawing === '' || code.trim().length !== 6} onClick={sign}>
                        {busy ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <PenLine className="h-4 w-4" />}
                        Signer
                    </Button>
                </div>
            )}
        </AuthLayout>
    );
}
