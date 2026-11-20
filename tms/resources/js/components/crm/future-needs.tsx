import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/react';
import { Lightbulb, Pencil } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

export interface FutureNeedsData {
    text: string | null;
    updated_on: string | null;
    updated_by: string | null;
}

/** « Besoins futurs » of an entreprise or a person (lot 1e): free text noted during the calls. */
export function FutureNeeds({ needs, updateUrl, canUpdate }: { needs: FutureNeedsData; updateUrl: string; canUpdate: boolean }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, processing, errors } = useForm({ future_needs: needs.text ?? '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(updateUrl, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    return (
        <section className="rounded-xl border p-4 lg:col-span-2" aria-labelledby="future-needs-title">
            <div className="mb-3 flex items-center justify-between gap-2">
                <h2 id="future-needs-title" className="flex items-center gap-2 text-sm font-semibold">
                    <Lightbulb className="h-4 w-4" />
                    Besoins futurs
                </h2>
                {canUpdate && !editing && (
                    <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(true)}>
                        <Pencil className="h-4 w-4" />
                        Modifier
                    </Button>
                )}
            </div>
            {editing ? (
                <form onSubmit={submit} className="grid gap-2">
                    <textarea
                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        aria-label="Besoins futurs"
                        rows={4}
                        maxLength={2000}
                        value={data.future_needs}
                        onChange={(e) => setData('future_needs', e.target.value)}
                    />
                    <InputError message={errors.future_needs} />
                    <div className="flex gap-2">
                        <Button type="submit" size="sm" disabled={processing}>
                            Enregistrer
                        </Button>
                        <Button type="button" size="sm" variant="ghost" onClick={() => setEditing(false)}>
                            Annuler
                        </Button>
                    </div>
                </form>
            ) : needs.text ? (
                <>
                    <p className="text-sm whitespace-pre-line">{needs.text}</p>
                    {needs.updated_on && (
                        <p className="text-muted-foreground mt-2 text-xs">
                            Mis à jour le {needs.updated_on}
                            {needs.updated_by ? ` par ${needs.updated_by}` : ''}
                        </p>
                    )}
                </>
            ) : (
                <p className="text-muted-foreground text-sm">Aucun besoin noté.</p>
            )}
        </section>
    );
}
