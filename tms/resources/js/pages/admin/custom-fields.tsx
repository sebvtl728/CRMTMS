import { OptionSelect, TextField, textareaClass } from '@/components/admissions/fields';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useState } from 'react';

interface Field {
    id: string;
    label: string;
    type: string;
    type_label: string;
    options: string[];
    required_for_admission: boolean;
    hidden: boolean;
    used: boolean;
}

interface Draft {
    label: string;
    type: string | null;
    options: string;
    required_for_admission: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Champs personnalisés', href: '/admin/custom-fields' }];

const empty: Draft = { label: '', type: 'text', options: '', required_for_admission: false };

function payload(d: Draft): { label: string; type: string | null; options: string[] | null; required_for_admission: boolean } {
    return {
        label: d.label,
        type: d.type,
        options:
            d.type === 'choice'
                ? d.options
                      .split('\n')
                      .map((o) => o.trim())
                      .filter(Boolean)
                : null,
        required_for_admission: d.required_for_admission,
    };
}

/**
 * Administration › Champs personnalisés: the organisme's own fields on the
 * candidature fiche. A field already filled is hidden, never deleted.
 */
export default function CustomFields({ fields, types }: { fields: Field[]; types: Option[]; status?: string }) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [draft, setDraft] = useState<Draft>(empty);
    const [editing, setEditing] = useState<string | null>(null);
    const [edit, setEdit] = useState<Draft>(empty);

    const form = (d: Draft, set: (d: Draft) => void, typeLocked: boolean) => (
        <div className="grid gap-3 sm:grid-cols-2">
            <TextField id="label" label="Libellé *" value={d.label} onChange={(v) => set({ ...d, label: v })} error={errors.label} />
            <OptionSelect
                id="type"
                label="Type *"
                value={d.type}
                options={types}
                onChange={(v) => set({ ...d, type: v })}
                error={errors.type}
                disabled={typeLocked}
            />
            {d.type === 'choice' && (
                <div className="grid gap-1 sm:col-span-2">
                    <Label htmlFor="options">Choix (un par ligne)</Label>
                    <textarea
                        id="options"
                        rows={4}
                        className={textareaClass}
                        value={d.options}
                        onChange={(e) => set({ ...d, options: e.target.value })}
                    />
                    <InputError message={errors.options} />
                </div>
            )}
            <Label className="flex items-center gap-2 font-normal sm:col-span-2">
                <input type="checkbox" checked={d.required_for_admission} onChange={(e) => set({ ...d, required_for_admission: e.target.checked })} />
                Obligatoire pour admettre un candidat
            </Label>
        </div>
    );

    const move = (index: number, delta: number) => {
        const ids = fields.map((f) => f.id);
        const [moved] = ids.splice(index, 1);
        ids.splice(index + delta, 0, moved);
        router.post('/admin/custom-fields/reorder', { ids }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Champs personnalisés" />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <Heading
                    title="Champs personnalisés"
                    description="Vos propres champs sur la fiche candidat, dans le bloc « Informations complémentaires »."
                />

                <section className="grid gap-3 rounded-xl border p-4">
                    <h2 className="text-sm font-semibold">Nouveau champ</h2>
                    {editing === null && form(draft, setDraft, false)}
                    <div>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() =>
                                router.post('/admin/custom-fields', payload(draft), { preserveScroll: true, onSuccess: () => setDraft(empty) })
                            }
                        >
                            Ajouter le champ
                        </Button>
                    </div>
                </section>

                {fields.length === 0 ? (
                    <div className="text-muted-foreground rounded-xl border p-8 text-center text-sm">Aucun champ personnalisé pour le moment.</div>
                ) : (
                    <ul className="grid gap-2">
                        {fields.map((f, i) => (
                            <li key={f.id} className="grid gap-3 rounded-xl border p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{f.label}</span>
                                        <Badge variant="secondary">{f.type_label}</Badge>
                                        {f.required_for_admission && <Badge variant="outline">Requis pour l'admission</Badge>}
                                        {f.hidden && <Badge variant="outline">Masqué</Badge>}
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            aria-label="Monter"
                                            disabled={i === 0}
                                            onClick={() => move(i, -1)}
                                        >
                                            <ArrowUp className="h-4 w-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            aria-label="Descendre"
                                            disabled={i === fields.length - 1}
                                            onClick={() => move(i, 1)}
                                        >
                                            <ArrowDown className="h-4 w-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                setEditing(f.id);
                                                setEdit({
                                                    label: f.label,
                                                    type: f.type,
                                                    options: f.options.join('\n'),
                                                    required_for_admission: f.required_for_admission,
                                                });
                                            }}
                                        >
                                            Modifier
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/admin/custom-fields/${f.id}/${f.hidden ? 'unhide' : 'hide'}`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {f.hidden ? 'Afficher' : 'Masquer'}
                                        </Button>
                                        {!f.used && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    confirm(`Supprimer le champ « ${f.label} » ?`) &&
                                                    router.delete(`/admin/custom-fields/${f.id}`, { preserveScroll: true })
                                                }
                                            >
                                                Supprimer
                                            </Button>
                                        )}
                                    </div>
                                </div>
                                {editing === f.id && (
                                    <div className="grid gap-3 border-t pt-3">
                                        {form(edit, setEdit, f.used)}
                                        {f.used && (
                                            <p className="text-muted-foreground text-xs">
                                                Champ rempli sur des candidatures : son type ne change plus et un choix utilisé ne peut pas être
                                                retiré.
                                            </p>
                                        )}
                                        <div className="flex gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                onClick={() =>
                                                    router.put(`/admin/custom-fields/${f.id}`, payload(edit), {
                                                        preserveScroll: true,
                                                        onSuccess: () => setEditing(null),
                                                    })
                                                }
                                            >
                                                Enregistrer
                                            </Button>
                                            <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(null)}>
                                                Annuler
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
