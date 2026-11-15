import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { centsInput, type RevenuePeriod, type RevenueRow } from '@/lib/revenue';
import { router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

/**
 * The targets of the displayed period, one amount per DAS typed the French way
 * (« 10 000 », « 10 000,50 »); empty clears a target. Only the changed lines
 * are sent, one after the other.
 */
export function RevenueTargetsDialog({
    open,
    period,
    rows,
    onClose,
}: {
    open: boolean;
    period: RevenuePeriod;
    rows: RevenueRow[];
    onClose: () => void;
}) {
    const [values, setValues] = useState<Record<string, string>>({});
    const [initial, setInitial] = useState<Record<string, string>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [saved, setSaved] = useState<string[]>([]);

    useEffect(() => {
        if (open) {
            const start = Object.fromEntries(rows.map((r) => [r.das_id, centsInput(r.target_cents)]));
            setValues(start);
            setInitial(start);
            setErrors({});
            setSaved([]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const changes = rows.filter((r) => (values[r.das_id] ?? '') !== (initial[r.das_id] ?? ''));
        setErrors({});
        setProcessing(true);

        const send = (index: number) => {
            if (index >= changes.length) {
                setProcessing(false);
                onClose();

                return;
            }
            const row = changes[index];
            router.put(
                route('crm.revenue.targets'),
                { das_id: row.das_id, period: period.type, key: period.key, amount: values[row.das_id] ?? '' },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        setInitial((i) => ({ ...i, [row.das_id]: values[row.das_id] ?? '' }));
                        setSaved((x) => [...x, row.das_id]);
                        send(index + 1);
                    },
                    onError: (errs) => {
                        setErrors({ [row.das_id]: errs.amount ?? errs.period ?? errs.das_id ?? 'Objectif refusé.' });
                        setProcessing(false);
                    },
                },
            );
        };
        send(0);
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>Objectifs · {period.label}</DialogTitle>
                        <DialogDescription>
                            Objectif de chiffre d'affaires HT de chaque DAS sur la période affichée. Laissez vide pour retirer un objectif.
                        </DialogDescription>
                    </DialogHeader>
                    {rows.map((row) => (
                        <div key={row.das_id} className="grid gap-1">
                            <Label htmlFor={`target-${row.das_id}`}>{row.das_label}</Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id={`target-${row.das_id}`}
                                    inputMode="decimal"
                                    autoComplete="off"
                                    placeholder="Ex. 10 000"
                                    value={values[row.das_id] ?? ''}
                                    onChange={(e) => setValues((v) => ({ ...v, [row.das_id]: e.target.value }))}
                                />
                                <span aria-hidden>€</span>
                            </div>
                            {saved.includes(row.das_id) && !errors[row.das_id] && <p className="text-xs text-green-600">Enregistré.</p>}
                            <InputError message={errors[row.das_id]} />
                        </div>
                    ))}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
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
    );
}
