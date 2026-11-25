import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { textareaClass, type Visit } from './booklet-types';

function VisitForm({ candidatureId, visit, onDone }: { candidatureId: string; visit: Visit | null; onDone: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm({ visited_on: visit?.visited_on ?? '', points: visit?.points ?? '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (visit) {
            put(route('admissions.booklet.visits.update', [candidatureId, visit.id]), options);
        } else {
            post(route('admissions.booklet.visits.store', candidatureId), options);
        }
    };

    return (
        <form onSubmit={submit} className="grid max-w-2xl gap-3 rounded-md border p-3">
            <div className="grid max-w-48 gap-2">
                <Label htmlFor="visit_date">Date de la visite *</Label>
                <Input id="visit_date" type="date" value={data.visited_on} onChange={(e) => setData('visited_on', e.target.value)} />
                <InputError message={errors.visited_on} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="visit_points">Points abordés *</Label>
                <textarea id="visit_points" rows={4} className={textareaClass} value={data.points} onChange={(e) => setData('points', e.target.value)} />
                <InputError message={errors.points} />
            </div>
            <div className="flex gap-2">
                <Button type="submit" disabled={processing}>
                    Enregistrer
                </Button>
                <Button type="button" variant="outline" onClick={onDone}>
                    Annuler
                </Button>
            </div>
        </form>
    );
}

/** Company visits of the trainer (the signatures of a visit stay by hand on the PDF). */
export function BookletVisits({ candidatureId, visits }: { candidatureId: string; visits: Visit[] }) {
    const [editing, setEditing] = useState<Visit | 'new' | null>(null);
    const day = (d: string) => d.split('-').reverse().join('/');

    return (
        <div className="flex flex-col gap-4">
            {editing === null && (
                <div>
                    <Button onClick={() => setEditing('new')}>Ajouter une visite</Button>
                </div>
            )}
            {editing !== null && <VisitForm candidatureId={candidatureId} visit={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />}
            {visits.length === 0 ? (
                <p className="text-muted-foreground text-sm">Aucune visite saisie.</p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {visits.map((v) => (
                        <li key={v.id} className="rounded-md border p-3 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium">
                                    {day(v.visited_on)}
                                    {v.trainer && <span className="text-muted-foreground font-normal"> - {v.trainer}</span>}
                                </span>
                                <span className="flex gap-1">
                                    <Button size="sm" variant="ghost" aria-label="Modifier la visite" onClick={() => setEditing(v)}>
                                        <Pencil className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-label="Supprimer la visite"
                                        onClick={() =>
                                            window.confirm(`Supprimer la visite du ${day(v.visited_on)} ?`) &&
                                            router.delete(route('admissions.booklet.visits.destroy', [candidatureId, v.id]), { preserveScroll: true })
                                        }
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </span>
                            </div>
                            <p className="mt-1 whitespace-pre-line">{v.points}</p>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
