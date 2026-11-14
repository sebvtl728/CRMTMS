import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { itemError } from '@/lib/crm';
import { useForm } from '@inertiajs/react';
import { LoaderCircle, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

export interface QuoteMessageDefaults {
    to: string;
    cc: string[];
    subject: string;
    body: string;
    delivers_nowhere: boolean;
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
    open,
    onClose,
}: {
    quoteId: string;
    number: string;
    kind: 'send' | 'remind';
    defaults: QuoteMessageDefaults;
    open: boolean;
    onClose: () => void;
}) {
    const { data, setData, transform, post, processing, errors, reset, clearErrors } = useForm({
        to: defaults.to,
        cc: defaults.cc.join(', '),
        subject: defaults.subject,
        body: defaults.body,
    });

    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    transform((d) => ({
        ...d,
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
