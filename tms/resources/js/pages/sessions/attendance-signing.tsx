import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import SignaturePad from '@/components/signature-pad';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/crm';
import { sendJson } from '@/lib/sessions';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { CheckCircle2, Clock, ExternalLink, LoaderCircle, Lock, Mail, PenLine, Tablet, Trash2 } from 'lucide-react';
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
    rows: {
        enrolment_id: string;
        name: string;
        signed_at: string | null;
        signature_id: string | null;
        /** A catch-up waiting for its confirmation (« Présences » tab). */
        catch_up_kind: 'catch_up_onsite' | 'catch_up_remote' | null;
        email: string | null;
        catch_up_link: { sent_at: string | null; email_status: string | null; expires_at: string } | null;
    }[];
    /** A past half-day: catch-up on site or by an e-mailed link (2026-11-21). */
    can_catch_up: boolean;
    catch_up: { open: boolean; public_url: string | null; expires_at: string | null };
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
    const [linkFor, setLinkFor] = useState<SlotState['rows'][number] | null>(null);
    const [linkEmail, setLinkEmail] = useState('');
    const [linkError, setLinkError] = useState<string | undefined>();

    useEffect(() => {
        if (state.status !== 'open' && !state.catch_up?.open) return;
        const timer = window.setInterval(() => {
            sendJson<SlotState>('GET', route('sessions.signing.state', state.id)).then((answer) => {
                if (answer.ok && answer.data) {
                    const next = answer.data;
                    setState((prev) => ({
                        ...next,
                        catch_up: { ...next.catch_up, public_url: next.catch_up.open ? (prev.catch_up?.public_url ?? null) : null },
                    }));
                }
            });
        }, 5000);

        return () => window.clearInterval(timer);
    }, [state.id, state.status, state.catch_up?.open]);

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
                        {state.can_catch_up && (
                            <div className="grid gap-2 border-t pt-3">
                                <p className="font-medium">Rattrapage</p>
                                <p className="text-muted-foreground text-sm">
                                    Un stagiaire présent n'a pas signé ? Faites-le signer sur cet appareil (rattrapage sur place, jusqu'à ce soir
                                    minuit) ou envoyez-lui un lien par e-mail. Chaque rattrapage est à confirmer dans l'onglet « Présences » de la
                                    session.
                                </p>
                                {state.catch_up.open && state.catch_up.public_url ? (
                                    <div className="flex flex-wrap gap-2">
                                        <Button asChild size="sm">
                                            <a href={state.catch_up.public_url} target="_blank" rel="noreferrer">
                                                <ExternalLink className="h-4 w-4" />
                                                Ouvrir la page de signature ici
                                            </a>
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={busy}
                                            onClick={() => act('POST', route('sessions.signing.catch-up.close', state.id))}
                                        >
                                            <Lock className="h-4 w-4" />
                                            Fermer le rattrapage
                                        </Button>
                                    </div>
                                ) : (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="justify-self-start"
                                        disabled={busy}
                                        onClick={() => act('POST', route('sessions.signing.catch-up', state.id))}
                                    >
                                        <Tablet className="h-4 w-4" />
                                        {state.catch_up.open ? 'Rouvrir le rattrapage sur place' : 'Rattrapage sur place'}
                                    </Button>
                                )}
                            </div>
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
                        <ul className="divide-y rounded-md border">
                            {state.rows.map((r) => (
                                <li key={r.enrolment_id} className="flex items-center justify-between gap-2 p-2 text-sm">
                                    <span>{r.name}</span>
                                    <span className="flex items-center gap-2">
                                        {r.signed_at ? (
                                            <Badge variant="secondary">
                                                <CheckCircle2 className="h-3 w-3" /> {formatDateTime(r.signed_at).split(' ').pop()}
                                            </Badge>
                                        ) : r.catch_up_link ? (
                                            <Badge variant="outline">
                                                <Mail className="h-3 w-3" />
                                                {r.catch_up_link.email_status === 'failed'
                                                    ? 'Lien non parti'
                                                    : `Lien envoyé${r.catch_up_link.sent_at ? ` le ${formatDateTime(r.catch_up_link.sent_at)}` : ''}`}
                                            </Badge>
                                        ) : state.status === 'validated' ? (
                                            <Badge variant="destructive">Absent</Badge>
                                        ) : (
                                            <Badge variant="outline">
                                                <Clock className="h-3 w-3" /> En attente
                                            </Badge>
                                        )}
                                        {r.catch_up_kind && <Badge variant="outline">Rattrapage à confirmer</Badge>}
                                        {!r.signed_at && state.can_catch_up && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                disabled={busy}
                                                onClick={() => {
                                                    setLinkFor(r);
                                                    setLinkEmail(r.email ?? '');
                                                    setLinkError(undefined);
                                                }}
                                            >
                                                <Mail className="h-4 w-4" />
                                                {r.catch_up_link ? 'Renvoyer un lien' : 'Envoyer un lien'}
                                            </Button>
                                        )}
                                        {r.signature_id && state.status !== 'validated' && !r.catch_up_kind && (
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

            <Dialog open={linkFor !== null} onOpenChange={(o) => !o && setLinkFor(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Lien de rattrapage – {linkFor?.name}</DialogTitle>
                        <DialogDescription>
                            Le stagiaire reçoit un lien personnel (7 jours, une seule utilisation), puis un code par e-mail pour signer sa présence du{' '}
                            {state.date_label}, {state.half_label.toLowerCase()}. Un nouveau lien remplace le précédent.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1">
                        <Label htmlFor="catch-up-email">Adresse e-mail</Label>
                        <Input id="catch-up-email" type="email" value={linkEmail} onChange={(e) => setLinkEmail(e.target.value)} />
                        <InputError message={linkError} />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setLinkFor(null)}>
                            Annuler
                        </Button>
                        <Button
                            disabled={busy || linkEmail.trim() === ''}
                            onClick={async () => {
                                if (!linkFor) return;
                                setBusy(true);
                                const answer = await sendJson<SlotState>('POST', route('sessions.signing.catch-up-links', state.id), {
                                    enrolment_id: linkFor.enrolment_id,
                                    email: linkEmail,
                                });
                                setBusy(false);
                                if (answer.ok && answer.data) {
                                    setState(answer.data);
                                    setLinkFor(null);
                                } else {
                                    setLinkError(answer.errors.email ?? answer.errors.enrolment_id ?? answer.message);
                                }
                            }}
                        >
                            <Mail className="h-4 w-4" />
                            Envoyer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

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
