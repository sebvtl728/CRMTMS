import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatSize, itemError } from '@/lib/crm';
import { useForm } from '@inertiajs/react';
import { LoaderCircle, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

export interface QuoteMessageDefaults {
    to: string;
    cc: string[];
    subject: string;
    body: string;
    delivers_nowhere: boolean;
}

/** A brochure proposed for the e-mail (current version of a brochure of the library). */
export interface BrochureChoice {
    version_id: string;
    title: string;
    size?: number;
}

/** A brochure version already joined to the sent e-mail of the quote. */
export interface SentBrochure {
    version_id: string;
    original_name: string;
    size: number;
}

/**
 * « Envoyer le devis » / « Relancer »: recipient (prefilled), up to five
 * copies (one per line or separated by commas), subject and message, sent
 * as plain text with the PDF attached.
 */
export function QuoteSendDialog({
    quoteId,
    number,
    kind,
    defaults,
    choices = [],
    sent = [],
    open,
    onClose,
}: {
    quoteId: string;
    number: string;
    kind: 'send' | 'remind';
    defaults: QuoteMessageDefaults;
    /** Sending: brochures of the lines (pre-ticked). */
    choices?: BrochureChoice[];
    /** Reminder: the brochures joined at sending (joined again as they were). */
    sent?: SentBrochure[];
    open: boolean;
    onClose: () => void;
}) {
    const { data, setData, transform, post, processing, errors, reset, clearErrors } = useForm({
        to: defaults.to,
        cc: defaults.cc.join(', '),
        subject: defaults.subject,
        body: defaults.body,
    });

    // « Joindre les plaquettes »: ticked by default, each brochure can be unticked, others added by search.
    const [join, setJoin] = useState(true);
    const [list, setList] = useState<BrochureChoice[]>(choices);
    const [picked, setPicked] = useState<string[]>(choices.map((c) => c.version_id));
    const [search, setSearch] = useState('');
    const [hits, setHits] = useState<BrochureChoice[]>([]);

    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
            setJoin(true);
            setList(choices);
            setPicked(choices.map((c) => c.version_id));
            setSearch('');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // Debounced lookup of the other brochures of the library.
    useEffect(() => {
        if (kind !== 'send' || !open || !join || search.trim().length < 2) {
            setHits([]);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(route('crm.quotes.brochure-options', { q: search.trim() }), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : { data: [] }))
                .then((json: { data: BrochureChoice[] }) => setHits(json.data.filter((h) => !list.some((c) => c.version_id === h.version_id))))
                .catch(() => undefined);
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [search, kind, open, join, list]);

    const addHit = (hit: BrochureChoice) => {
        setList((l) => [...l, hit]);
        setPicked((p) => [...p, hit.version_id]);
        setSearch('');
        setHits([]);
    };

    transform((d) => ({
        ...d,
        ...(kind === 'send' ? { brochure_version_ids: join ? picked : [] } : {}),
        cc: d.cc
            .split(/[\s,;]+/)
            .map((c) => c.trim())
            .filter((c) => c !== ''),
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route(kind === 'send' ? 'crm.quotes.send' : 'crm.quotes.remind', quoteId), { preserveScroll: true, onSuccess: () => onClose() });
    };

    const errs = errors as Record<string, string | undefined>;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>{kind === 'send' ? `Envoyer le devis ${number}` : `Relancer le devis ${number}`}</DialogTitle>
                        <DialogDescription>
                            {kind === 'send'
                                ? 'Le devis est figé et son PDF archivé : il ne se modifie plus ensuite. Le PDF est joint au message.'
                                : 'Le même PDF est joint au message de relance.'}
                        </DialogDescription>
                    </DialogHeader>

                    {defaults.delivers_nowhere && (
                        <p className="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                            <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
                            Aucun serveur d'envoi n'est configuré (Administration › Connecteurs) : le message ne partira pas réellement.
                        </p>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="quote-to">Destinataire *</Label>
                        <Input id="quote-to" type="email" value={data.to} onChange={(e) => setData('to', e.target.value)} aria-invalid={Boolean(errs.to)} />
                        <InputError message={errs.to} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="quote-cc">Copie (5 adresses au plus)</Label>
                        <Input id="quote-cc" value={data.cc} onChange={(e) => setData('cc', e.target.value)} placeholder="adresse@exemple.fr, autre@exemple.fr" />
                        <InputError message={errs.cc ?? itemError(errs, 'cc')} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="quote-subject">Objet *</Label>
                        <Input id="quote-subject" maxLength={200} value={data.subject} onChange={(e) => setData('subject', e.target.value)} />
                        <InputError message={errs.subject} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="quote-body">Message *</Label>
                        <textarea
                            id="quote-body"
                            rows={9}
                            maxLength={10000}
                            value={data.body}
                            onChange={(e) => setData('body', e.target.value)}
                            className="border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden"
                        />
                        <InputError message={errs.body} />
                    </div>

                    {kind === 'send' && (
                        <div className="grid gap-2 rounded-md border p-3">
                            <div className="flex items-center gap-2">
                                <Checkbox id="quote-join-brochures" checked={join} onCheckedChange={(c) => setJoin(c === true)} />
                                <Label htmlFor="quote-join-brochures">Joindre les plaquettes</Label>
                            </div>
                            {join && (
                                <>
                                    {list.length === 0 && <p className="text-muted-foreground text-sm">Aucune plaquette rattachée aux formations du devis.</p>}
                                    <ul className="grid gap-1">
                                        {list.map((c) => (
                                            <li key={c.version_id} className="flex items-center gap-2 text-sm">
                                                <Checkbox
                                                    id={`quote-brochure-${c.version_id}`}
                                                    checked={picked.includes(c.version_id)}
                                                    onCheckedChange={(on) =>
                                                        setPicked((p) => (on === true ? [...p, c.version_id] : p.filter((id) => id !== c.version_id)))
                                                    }
                                                />
                                                <Label htmlFor={`quote-brochure-${c.version_id}`} className="font-normal">
                                                    {c.title}
                                                    {c.size !== undefined && <span className="text-muted-foreground"> ({formatSize(c.size)})</span>}
                                                </Label>
                                            </li>
                                        ))}
                                    </ul>
                                    <Input
                                        aria-label="Ajouter une autre plaquette"
                                        placeholder="Ajouter une autre plaquette (titre)"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                    />
                                    {hits.length > 0 && (
                                        <ul className="grid gap-1 rounded-md border p-1">
                                            {hits.map((h) => (
                                                <li key={h.version_id}>
                                                    <button type="button" className="hover:bg-muted w-full rounded px-2 py-1 text-left text-sm" onClick={() => addHit(h)}>
                                                        {h.title}
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </>
                            )}
                            <InputError message={errs.brochure_version_ids ?? itemError(errs, 'brochure_version_ids')} />
                        </div>
                    )}
                    {kind === 'remind' && sent.length > 0 && (
                        <p className="text-muted-foreground text-sm">
                            Plaquettes jointes comme à l'envoi : {sent.map((b) => `${b.original_name} (${formatSize(b.size)})`).join(', ')}.
                        </p>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            {kind === 'send' ? 'Envoyer' : 'Envoyer la relance'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
