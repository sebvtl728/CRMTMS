import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { LoaderCircle, PenLine } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export interface QuoteSignature {
    status: 'pending' | 'signed' | 'cancelled';
    signed_at: string | null;
    email: string | null;
}

/** « Faire signer en ligne » a sent quote (« Bon pour accord »): the customer signs with a code received by e-mail. */
export function QuoteSignatureButton({
    quoteId,
    number,
    signature,
    defaultEmail,
}: {
    quoteId: string;
    number: string;
    signature: QuoteSignature | null;
    defaultEmail?: string | null;
}) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors } = useForm({ email: signature?.email ?? defaultEmail ?? '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/crm/quotes/${quoteId}/signature`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                <PenLine className="h-4 w-4" />
                {signature?.status === 'pending' ? 'Renvoyer le lien de signature' : 'Faire signer en ligne'}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={submit} className="grid gap-3">
                        <DialogHeader>
                            <DialogTitle>Bon pour accord en ligne : devis {number}</DialogTitle>
                            <DialogDescription>
                                Le client reçoit un lien personnel (30 jours), lit le devis et le signe avec un code reçu par e-mail. Une fois signé,
                                le devis passe à « Accepté » et le PDF signé devient son exemplaire signé. Un nouvel envoi remplace le lien précédent.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-1">
                            <Label htmlFor="quote-sign-email">Adresse du signataire</Label>
                            <Input id="quote-sign-email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                            <InputError message={errors.email} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Fermer
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <PenLine className="h-4 w-4" />}
                                Envoyer le lien
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
