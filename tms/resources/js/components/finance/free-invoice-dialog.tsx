import { FichePicker, type PickedFiche } from '@/components/finance/fiche-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Option } from '@/lib/crm';
import { useForm } from '@inertiajs/react';
import { FilePlus2 } from 'lucide-react';
import { useState } from 'react';

/** « Nouvelle facture libre »: a draft without dossier (client, issuing company, buyer's references). */
export function FreeInvoiceDialog({ companies }: { companies: Option[] }) {
    const [open, setOpen] = useState(false);
    const [client, setClient] = useState<PickedFiche | null>(null);
    const form = useForm({
        company_id: companies[0]?.value ?? '',
        recipient_type: '',
        recipient_id: '',
        buyer_reference: '',
        service_code: '',
        period_from: '',
        period_to: '',
    });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <Button type="button" onClick={() => setOpen(true)}>
                <FilePlus2 className="h-4 w-4" />
                Nouvelle facture libre
            </Button>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Nouvelle facture libre</DialogTitle>
                    <DialogDescription>
                        Une facture sans dossier (marché public, mandataire d'un groupement…) : ses lignes se saisissent ensuite, une ligne
                        peut désigner un co-traitant.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="grid gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/finance/invoices/free');
                    }}
                >
                    <div className="grid gap-1">
                        <Label htmlFor="free-client">Client</Label>
                        <FichePicker
                            id="free-client"
                            value={client}
                            onChange={(f) => {
                                setClient(f);
                                form.setData((d) => ({ ...d, recipient_type: f?.type ?? '', recipient_id: f?.id ?? '' }));
                            }}
                        />
                        <InputError message={form.errors.recipient_id} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="free-company">Société qui émet la facture</Label>
                        <select
                            id="free-company"
                            className="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
                            value={form.data.company_id}
                            onChange={(e) => form.setData('company_id', e.target.value)}
                        >
                            {companies.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.company_id} />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <Label htmlFor="free-buyer">N° d'engagement (facultatif)</Label>
                            <Input id="free-buyer" value={form.data.buyer_reference} onChange={(e) => form.setData('buyer_reference', e.target.value)} />
                            <InputError message={form.errors.buyer_reference} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="free-service">Code service (facultatif)</Label>
                            <Input id="free-service" value={form.data.service_code} onChange={(e) => form.setData('service_code', e.target.value)} />
                            <InputError message={form.errors.service_code} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="free-from">Période du</Label>
                            <Input id="free-from" type="date" value={form.data.period_from} onChange={(e) => form.setData('period_from', e.target.value)} />
                            <InputError message={form.errors.period_from} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="free-to">au</Label>
                            <Input id="free-to" type="date" value={form.data.period_to} onChange={(e) => form.setData('period_to', e.target.value)} />
                            <InputError message={form.errors.period_to} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={form.processing || !client}>
                            Créer le brouillon
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
