import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import SignaturePad from '@/components/signature-pad';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatDay } from '@/lib/crm';
import { sendJson } from '@/lib/sessions';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { CheckCircle2, Clock, ExternalLink, LoaderCircle, Lock, LogIn, PenLine, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

/** The live state of a half-day (SlotRows::state): full names, the trainer is authenticated. */
interface SlotState {
    id: string;
    session: string | null;
    date_label: string;
    half_label: string;
    status: 'open' | 'closed' | 'validated';
    public_url: string | null;
    qr_svg: string | null;
    is_today: boolean;
    validated_at: string | null;
    validated_by: string | null;
    rows: { enrolment_id: string; name: string; signed_at: string | null; signature_id: string | null }[];
    /** Enrolled, but entering the training after this day: not expected yet. */
    late_entrants: { enrolment_id: string; name: string; entered_on: string | null }[];
    /** « Faire entrer un stagiaire en formation » on this session. */
    can_advance_entry: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Mon planning', href: '/mon-planning' }];

/**
 * The trainer's screen of a half-day: the QR code to show the class (or the
 * page itself on this device, passed round), who signed, then « Signer et
 * valider »: the attendance is written from the signatures.
 */
export default function AttendanceSigning({ state: initial }: { state: SlotState }) {
    const [state, setState] = useState<SlotState>(initial);
    const [error, setError] = useState<string | null>(null);
    const [validating, setValidating] = useState(false);
    const [drawing, setDrawing] = useState('');
    const [drawingError, setDrawingError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (state.status !== 'open') return;
        const timer = window.setInterval(() => {
            sendJson<SlotState>('GET', route('sessions.signing.state', state.id)).then((answer) => {
                if (answer.ok && answer.data) setState(answer.data);
            });
        }, 5000);

        return () => window.clearInterval(timer);
    }, [state.id, state.status]);

    const act = async (method: 'POST' | 'DELETE', url: string, body?: unknown): Promise<boolean> => {
        setBusy(true);
        const answer = await sendJson<SlotState>(method, url, body);
        setBusy(false);
        if (answer.ok && answer.data) {
            setState(answer.data);
            setError(null);
            return true;
        }
        if (answer.errors.drawing) setDrawingError(answer.errors.drawing);
        else setError(answer.message);

        return false;
    };

    const signed = state.rows.filter((r) => r.signed_at !== null).length;
    const missing = state.rows.length - signed;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Émargement en ligne" />
            <div className="grid gap-6 p-4">
                <Heading title={`Émargement – ${state.half_label}`} description={`${state.session ?? ''} · ${state.date_label}`} />
                {error && <p className="text-destructive text-sm">{error}</p>}

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="grid content-start gap-3 rounded-md border p-4">
                        {state.status === 'open' && state.qr_svg && state.public_url ? (
                            <>
                                <p className="text-sm">Scannez le QR code avec votre téléphone, choisissez votre nom et signez.</p>
                                <div
                                    className="mx-auto w-full max-w-xs rounded bg-white p-2 [&>svg]:h-auto [&>svg]:w-full"
                                    role="img"
                                    aria-label="QR code de la page de signature"
                                    dangerouslySetInnerHTML={{ __html: state.qr_svg }}
                                />
                                <p className="text-center font-mono text-xs break-all">{state.public_url}</p>
                                <div className="flex flex-wrap gap-2">
                                    <Button asChild variant="outline" size="sm">
                                        <a href={state.public_url} target="_blank" rel="noreferrer">
                                            <ExternalLink className="h-4 w-4" />
                                            Ouvrir la page de signature ici
                                        </a>
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={busy}
                                        onClick={() => act('POST', route('sessions.signing.close', state.id))}
                                    >
                                        <Lock className="h-4 w-4" />
                                        Fermer le lien
                                    </Button>
                                </div>
                            </>
                        ) : state.status === 'validated' ? (
                            <p className="flex items-center gap-2 text-sm">
                                <CheckCircle2 className="h-4 w-4 text-green-600" />
                                Validé par {state.validated_by} le {state.validated_at ? formatDateTime(state.validated_at) : ''}. Les présences sont
                                enregistrées.
                            </p>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                Le lien est fermé : les stagiaires ne peuvent plus signer. Vous pouvez encore valider la demi-journée.
                            </p>
                        )}
                        {state.status !== 'validated' && (
                            <Button disabled={busy} onClick={() => setValidating(true)}>
                                <PenLine className="h-4 w-4" />
                                Signer et valider
                            </Button>
                        )}
                    </section>

                    <section className="grid content-start gap-2" aria-live="polite">
                        <h3 className="font-medium">
                            Signatures : {signed} / {state.rows.length}
                        </h3>
                        {state.rows.length === 0 && (
                            <p className="text-muted-foreground rounded-md border p-3 text-sm">
                                Aucun stagiaire n'est attendu ce jour-là : vérifiez les inscriptions et leur date d'entrée en formation.
                            </p>
                        )}
                        {(state.late_entrants ?? []).length > 0 && (
                            <div className="grid gap-2 rounded-md border p-3 text-sm">
                                <p className="font-medium">Inscrits qui entrent en formation plus tard</p>
                                <p className="text-muted-foreground">
                                    {state.can_advance_entry
                                        ? "« Faire entrer ce jour-là » avance sa date d'entrée au jour de cette demi-journée (noté dans l'historique du dossier) : il peut alors signer."
                                        : "Pour qu'ils signent, une personne ayant le droit « Faire entrer un stagiaire en formation » doit avancer leur date d'entrée."}
                                </p>
                                <ul className="divide-y rounded-md border">
                                    {state.late_entrants.map((r) => (
                                        <li key={r.enrolment_id} className="flex flex-wrap items-center justify-between gap-2 p-2">
                                            <span>
                                                {r.name}
                                                <span className="text-muted-foreground"> · entrée le {formatDay(r.entered_on)}</span>
                                            </span>
                                            {state.can_advance_entry && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        act('POST', route('sessions.signing.advance-entry', state.id), {
                                                            enrolment_id: r.enrolment_id,
                                                        })
                                                    }
                                                >
                                                    <LogIn className="h-4 w-4" />
                                                    Faire entrer ce jour-là
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        <ul className="divide-y rounded-md border">
                            {state.rows.map((r) => (
                                <li key={r.enrolment_id} className="flex items-center justify-between gap-2 p-2 text-sm">
                                    <span>{r.name}</span>
                                    <span className="flex items-center gap-2">
                                        {r.signed_at ? (
                                            <Badge variant="secondary">
                                                <CheckCircle2 className="h-3 w-3" /> {formatDateTime(r.signed_at).split(' ').pop()}
                                            </Badge>
                                        ) : state.status === 'validated' ? (
                                            <Badge variant="destructive">Absent</Badge>
                                        ) : (
                                            <Badge variant="outline">
                                                <Clock className="h-3 w-3" /> En attente
                                            </Badge>
                                        )}
                                        {r.signature_id && state.status !== 'validated' && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Effacer la signature de ${r.name}`}
                                                disabled={busy}
                                                onClick={() => {
                                                    if (window.confirm(`Effacer la signature de ${r.name} ? Le stagiaire pourra signer à nouveau.`)) {
                                                        act('DELETE', route('sessions.signing.erase', { slot: state.id, signature: r.signature_id }));
                                                    }
                                                }}
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
            </div>

            <Dialog open={validating} onOpenChange={setValidating}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Signer et valider – {state.half_label}</DialogTitle>
                        <DialogDescription>
                            {missing > 0
                                ? `${missing} stagiaire${missing > 1 ? 's n’ont' : ' n’a'} pas signé : les stagiaires qui n'ont pas signé seront notés absents.`
                                : 'Tous les stagiaires ont signé.'}{' '}
                            Vous pourrez corriger ensuite dans la feuille de présences.
                        </DialogDescription>
                    </DialogHeader>
                    <SignaturePad id="validator-drawing" onChange={setDrawing} invalid={!!drawingError} />
                    <InputError message={drawingError} />
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setValidating(false)}>
                            Annuler
                        </Button>
                        <Button
                            disabled={busy || drawing === ''}
                            onClick={async () => {
                                setDrawingError(undefined);
                                if (await act('POST', route('sessions.signing.validate', state.id), { drawing })) setValidating(false);
                            }}
                        >
                            {busy ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <PenLine className="h-4 w-4" />}
                            Valider la demi-journée
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
