import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { LoaderCircle, TriangleAlert } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

export interface InvoiceMessageDefaults {
    to: string;
    subject: string;
    body: string;
    delivers_nowhere: boolean;
}

/**
 * « Envoyer » an invoice or a credit note: the recipient (prefilled from the
 * address frozen at validation, editable), the subject and the message, sent
 * as plain text with the archived PDF attached.
 */
export function InvoiceSendDialog({
    invoiceId,
    title,
    defaults,
    open,
    onClose,
    action,
    heading,
}: {
    invoiceId: string;
    title: string;
    defaults: InvoiceMessageDefaults;
    open: boolean;
    onClose: () => void;
    /** Where the message goes (default: « Envoyer » the invoice); a reminder posts elsewhere. */
    action?: string;
    heading?: string;
}) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        to: defaults.to,
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

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(action ?? route('finance.invoices.send', invoiceId), { preserveScroll: true, onSuccess: () => onClose() });
    };

    const errs = errors as Record<string, string | undefined>;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>{heading ?? `Envoyer ${title}`}</DialogTitle>
                        <DialogDescription>Le PDF archivé de la pièce est joint au message ; il n'est jamais produit de nouveau.</DialogDescription>
                    </DialogHeader>

                    {defaults.delivers_nowhere && (
                        <p className="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                            <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
                            Aucun serveur d'envoi n'est configuré (Administration › Connecteurs) : le message ne partira pas réellement.
                        </p>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="invoice-to">Destinataire *</Label>
                        <Input
                            id="invoice-to"
                            type="email"
                            value={data.to}
                            onChange={(e) => setData('to', e.target.value)}
                            aria-invalid={Boolean(errs.to)}
                        />
                        <p className="text-muted-foreground text-xs">
                            Adresse de la fiche à la validation ; modifiable pour cet envoi (la pièce, elle, ne change pas).
                        </p>
                        <InputError message={errs.to} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="invoice-subject">Objet *</Label>
                        <Input id="invoice-subject" maxLength={200} value={data.subject} onChange={(e) => setData('subject', e.target.value)} />
                        <InputError message={errs.subject} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="invoice-body">Message *</Label>
                        <textarea
                            id="invoice-body"
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
                            Envoyer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
