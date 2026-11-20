import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { FormEventHandler } from 'react';

export interface UnavailabilityRow {
    id: string;
    /** « Du 29/03/2027 09:00 au 29/03/2027 17:30 » (Paris time). */
    label: string;
    starts_at: string;
    ends_at: string;
    reason: string | null;
}

/**
 * Unavailabilities of a trainer: the list, an add form (start and end in
 * Paris time, optional reason) and a delete button per row. The routes are
 * those of the manager (a trainer page) or of the trainer himself.
 */
export function Unavailabilities({
    rows,
    storeUrl,
    destroyUrl,
}: {
    rows: UnavailabilityRow[];
    storeUrl: string;
    destroyUrl: (id: string) => string;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({ starts_at: '', ends_at: '', reason: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(storeUrl, { preserveScroll: true, onSuccess: () => reset() });
    };

    const remove = (row: UnavailabilityRow) => {
        if (window.confirm(`Supprimer l'indisponibilité ${row.label.toLowerCase()} ?`)) {
            router.delete(destroyUrl(row.id), { preserveScroll: true });
        }
    };

    return (
        <div className="flex flex-col gap-4">
            {rows.length === 0 ? (
                <p className="text-muted-foreground text-sm">Aucune indisponibilité.</p>
            ) : (
                <ul className="divide-y rounded-md border text-sm">
                    {rows.map((row) => (
                        <li key={row.id} className="flex items-center justify-between gap-2 px-3 py-2">
                            <span>
                                {row.label}
                                {row.reason && <span className="text-muted-foreground"> · {row.reason}</span>}
                            </span>
                            <Button variant="ghost" size="icon" aria-label={`Supprimer l'indisponibilité ${row.label}`} onClick={() => remove(row)}>
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            <form onSubmit={submit} className="grid gap-3 sm:grid-cols-[auto_auto_1fr_auto] sm:items-end">
                <div className="grid gap-2">
                    <Label htmlFor="unavailability_starts">Du *</Label>
                    <Input
                        id="unavailability_starts"
                        type="datetime-local"
                        value={data.starts_at}
                        onChange={(e) => setData('starts_at', e.target.value)}
                    />
                    <InputError message={errors.starts_at} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="unavailability_ends">Au *</Label>
                    <Input id="unavailability_ends" type="datetime-local" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                    <InputError message={errors.ends_at} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="unavailability_reason">Motif</Label>
                    <Input id="unavailability_reason" maxLength={200} value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                    <InputError message={errors.reason} />
                </div>
                <Button type="submit" disabled={processing}>
                    Ajouter
                </Button>
            </form>
        </div>
    );
}
