import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, itemError } from '@/lib/crm';
import { useForm } from '@inertiajs/react';
import { LoaderCircle, Paperclip, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

export interface BrochureRecipient {
    id: string;
    name: string;
    email: string | null;
}

/** Subject and message of the « Envoi d'une plaquette » template; {destinataire} is replaced by the chosen recipient's name. */
interface Template {
    subject: string;
    body: string;
}

const fillTemplate = (text: string, name: string) => text.split('{destinataire}').join(name);

/** What a fiche page passes to the window (null: not allowed / nobody to write to). */
export interface BrochureSendProps {
    recipients: BrochureRecipient[];
    template: Template;
    delivers_nowhere: boolean;
}

interface Choice {
    version_id: string;
    title: string;
}

export interface SentBrochure {
    brochure_id: string;
    version_id: string;
    title: string;
    downloadable: boolean;
}

export interface BrochureSendingRow {
    id: string;
    at: string;
    author: string | null;
    recipient: string;
    titles: string[];
    versions: SentBrochure[];
    status: string;
    status_label: string;
}

export function SendingStatus({ status, label }: { status: string; label: string }) {
    return <Badge variant={status === 'failed' ? 'destructive' : status === 'sent' ? 'secondary' : 'default'}>{label}</Badge>;
}

/** The brochures of a sending, each downloadable (the version sent) when the user may. */
export function SentTitles({ versions }: { versions: SentBrochure[] }) {
    return (
        <>
            {versions.map((v, i) => (
                <span key={v.version_id}>
                    {i > 0 && ', '}
                    {v.downloadable ? (
                        <a href={route('catalogue.brochures.versions.download', [v.brochure_id, v.version_id])} className="underline">
                            {v.title}
                        </a>
                    ) : (
                        v.title
                    )}
                </span>
            ))}
        </>
    );
}

/** Latest sendings of an opportunity (never the address nor the message). */
export function BrochureSendingList({ rows }: { rows: BrochureSendingRow[] }) {
    if (rows.length === 0) return <p className="text-muted-foreground text-sm">Aucune plaquette envoyée.</p>;

    return (
        <ul className="grid gap-2">
            {rows.map((r) => (
                <li key={r.id} className="flex flex-wrap items-baseline justify-between gap-x-3 rounded-lg border p-3 text-sm">
                    <span>
                        <span className="font-medium">{r.recipient}</span> : <SentTitles versions={r.versions} />
                    </span>
                    <span className="text-muted-foreground flex items-center gap-2 text-xs">
                        <SendingStatus status={r.status} label={r.status_label} />
                        {formatDateTime(r.at)}
                        {r.author && ` · ${r.author}`}
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** « Envoyer une plaquette » button and window (entreprise, person or opportunity). */
export function BrochureSendButton({ send, routeName, subjectId }: { send: BrochureSendProps | null; routeName: string; subjectId: string }) {
    const [open, setOpen] = useState(false);
    if (send === null) return null;

    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                <Paperclip className="h-4 w-4" />
                Envoyer une plaquette
            </Button>
            <BrochureSendDialog send={send} routeName={routeName} subjectId={subjectId} open={open} onClose={() => setOpen(false)} />
        </>
    );
}

function BrochureSendDialog({
    send,
    routeName,
    subjectId,
    open,
    onClose,
}: {
    send: BrochureSendProps;
    routeName: string;
    subjectId: string;
    open: boolean;
    onClose: () => void;
}) {
    const withMail = send.recipients.filter((r) => r.email !== null);
    const first = withMail[0] ?? send.recipients[0];
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        recipient_person_id: first?.id ?? '',
        subject: fillTemplate(send.template.subject, first?.name ?? ''),
        message: fillTemplate(send.template.body, first?.name ?? ''),
        versions: [] as string[],
    });
    const [picked, setPicked] = useState<Choice[]>([]);
    const [search, setSearch] = useState('');
    const [hits, setHits] = useState<Choice[]>([]);

    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
            setPicked([]);
            setSearch('');
            setHits([]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // Debounced search of the library (current versions).
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(route('crm.brochures.options', search.trim().length >= 2 ? { q: search.trim() } : {}), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : { data: [] }))
                .then((json: { data: Choice[] }) => setHits(json.data.slice(0, 30)))
                .catch(() => undefined);
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [search, open]);

    const choose = (id: string) => {
        const previous = send.recipients.find((x) => x.id === data.recipient_person_id);
        const next = send.recipients.find((x) => x.id === id);
        // Text already typed is kept: only what still equals the proposal for the previous recipient is refilled.
        setData((d) => ({
            ...d,
            recipient_person_id: id,
            subject:
                d.subject === fillTemplate(send.template.subject, previous?.name ?? '')
                    ? fillTemplate(send.template.subject, next?.name ?? '')
                    : d.subject,
            message:
                d.message === fillTemplate(send.template.body, previous?.name ?? '') ? fillTemplate(send.template.body, next?.name ?? '') : d.message,
        }));
    };
    const toggle = (c: Choice, on: boolean) => {
        const next = on ? [...picked, c] : picked.filter((p) => p.version_id !== c.version_id);
        setPicked(next);
        setData(
            'versions',
            next.map((p) => p.version_id),
        );
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route(routeName, subjectId), { preserveScroll: true, onSuccess: () => onClose() });
    };

    const errs = errors as Record<string, string | undefined>;
    const shown = [...picked, ...hits.filter((h) => !picked.some((p) => p.version_id === h.version_id))];

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>Envoyer une plaquette</DialogTitle>
                        <DialogDescription>Les plaquettes choisies sont jointes au message (20 Mo au plus en tout).</DialogDescription>
                    </DialogHeader>

                    {send.delivers_nowhere && (
                        <p className="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                            <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
                            Aucun serveur d'envoi n'est configuré (Administration › Connecteurs) : le message ne partira pas réellement.
                        </p>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="brochure-recipient">Destinataire *</Label>
                        <select
                            id="brochure-recipient"
                            value={data.recipient_person_id}
                            onChange={(e) => choose(e.target.value)}
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            {send.recipients.map((r) => (
                                <option key={r.id} value={r.id} disabled={r.email === null}>
                                    {r.name} {r.email ? `(${r.email})` : "(pas d'adresse e-mail)"}
                                </option>
                            ))}
                        </select>
                        <InputError message={errs.recipient_person_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Plaquettes *</Label>
                        <Input
                            aria-label="Rechercher une plaquette"
                            placeholder="Rechercher une plaquette (titre)"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <ul className="grid max-h-40 gap-1 overflow-y-auto rounded-md border p-2">
                            {shown.length === 0 && <li className="text-muted-foreground text-sm">Aucune plaquette trouvée.</li>}
                            {shown.map((c) => (
                                <li key={c.version_id} className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        id={`send-brochure-${c.version_id}`}
                                        checked={picked.some((p) => p.version_id === c.version_id)}
                                        onCheckedChange={(on) => toggle(c, on === true)}
                                    />
                                    <Label htmlFor={`send-brochure-${c.version_id}`} className="font-normal">
                                        {c.title}
                                    </Label>
                                </li>
                            ))}
                        </ul>
                        <InputError message={errs.versions ?? itemError(errs, 'versions')} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="brochure-subject">Objet *</Label>
                        <Input id="brochure-subject" maxLength={200} value={data.subject} onChange={(e) => setData('subject', e.target.value)} />
                        <InputError message={errs.subject} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="brochure-message">Message *</Label>
                        <textarea
                            id="brochure-message"
                            rows={8}
                            maxLength={10000}
                            value={data.message}
                            onChange={(e) => setData('message', e.target.value)}
                            className="border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden"
                        />
                        <InputError message={errs.message} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Envoyer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
