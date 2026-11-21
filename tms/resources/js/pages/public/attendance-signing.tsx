import InputError from '@/components/input-error';
import SignaturePad from '@/components/signature-pad';
import { Button } from '@/components/ui/button';
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
}

/**
 * A half-day's signing page (no account): pick one's name, sign in the box,
 * « Je signe ma présence ». The same device may be passed round: after the
 * thank-you, the list comes back without the names already signed.
 */
export default function AttendanceSigning({ state, organisme, session, date, half_label, trainees = [], expected = 0, post_url }: Props) {
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
