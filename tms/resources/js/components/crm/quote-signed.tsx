import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FileSignature, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export interface SignedCopy {
    name: string | null;
    at: string | null;
    url: string;
}

/**
 * « Joindre le devis signé » (or « Remplacer ») on an accepted quote: PDF,
 * JPEG or PNG sent back by the customer, 10 Mo at most.
 */
export function QuoteSignedButton({ quoteId, number, signed }: { quoteId: string; number: string; signed: SignedCopy | null }) {
    const [open, setOpen] = useState(false);
    const { setData, post, processing, errors, reset, clearErrors } = useForm<{ signed: File | null }>({ signed: null });

    const start = () => {
        reset();
        clearErrors();
        setOpen(true);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('crm.quotes.signed.store', quoteId), { preserveScroll: true, forceFormData: true, onSuccess: () => setOpen(false) });
    };

    return (
        <>
            <Button variant="outline" onClick={start}>
                <FileSignature className="h-4 w-4" />
                {signed ? 'Remplacer le devis signé' : 'Joindre le devis signé'}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <form onSubmit={submit} className="grid gap-4" noValidate>
                        <DialogHeader>
                            <DialogTitle>Devis {number} signé</DialogTitle>
                            <DialogDescription>
                                {signed
                                    ? 'Le nouveau fichier remplace l’exemplaire signé déjà joint.'
                                    : 'Joignez l’exemplaire signé renvoyé par le client.'}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="quote-signed-file">Devis signé</Label>
                            <Input
                                id="quote-signed-file"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                onChange={(e) => setData('signed', e.target.files?.[0] ?? null)}
                            />
                            <p className="text-muted-foreground text-xs">PDF, JPEG ou PNG, 10 Mo au maximum.</p>
                            <InputError message={errors.signed} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Annuler
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                                Enregistrer
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
