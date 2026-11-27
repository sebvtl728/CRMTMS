import { OptionSelect, TextField, textareaClass } from '@/components/admissions/fields';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useState } from 'react';

interface Field {
    id: string;
    label: string;
    type: string;
    type_label: string;
    options: string[];
    required: boolean;
    hidden: boolean;
    used: boolean;
}

interface Draft {
    label: string;
    type: string | null;
    options: string;
    required: boolean;
}

interface EntityInfo {
    records: string;
    description: string;
    required_label: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Champs personnalisés', href: '/admin/custom-fields' }];

const empty: Draft = { label: '', type: 'text', options: '', required: false };

function payload(d: Draft): { label: string; type: string | null; options: string[] | null; required: boolean } {
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
        required: d.required,
    };
}

/**
 * Administration › Champs personnalisés: the organisme's own fields, one tab
 * per kind of fiche (candidatures, formations…). A field already filled is
 * hidden, never deleted.
 */
interface Props {
    entity: string;
    entity_info: EntityInfo;
    entities: Option[];
    fields: Field[];
    types: Option[];
    status?: string;
}

/** A tab starts with an empty form: its types differ from the other tab's. */
export default function CustomFields(props: Props) {
    return <EntityFields key={props.entity} {...props} />;
}

function EntityFields({ entity, entity_info, entities, fields, types }: Props) {
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
                <input type="checkbox" checked={d.required} onChange={(e) => set({ ...d, required: e.target.checked })} />
                {entity_info.required_label}
            </Label>
        </div>
    );

    const move = (index: number, delta: number) => {
        const ids = fields.map((f) => f.id);
        const [moved] = ids.splice(index, 1);
        ids.splice(index + delta, 0, moved);
        router.post('/admin/custom-fields/reorder', { entity, ids }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Champs personnalisés" />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <Heading title="Champs personnalisés" description={entity_info.description} />

                {entities.length > 1 && (
                    <nav aria-label="Fiches" className="flex flex-wrap gap-1 border-b">
                        {entities.map((e) => (
                            <Link
                                key={e.value}
                                href={`/admin/custom-fields?entity=${e.value}`}
                                aria-current={e.value === entity ? 'page' : undefined}
                                className={`-mb-px border-b-2 px-3 py-2 text-sm ${
                                    e.value === entity
                                        ? 'border-primary font-medium'
                                        : 'text-muted-foreground hover:text-foreground border-transparent'
                                }`}
                            >
                                {e.label}
                            </Link>
                        ))}
                    </nav>
                )}

                <section className="grid gap-3 rounded-xl border p-4">
                    <h2 className="text-sm font-semibold">Nouveau champ</h2>
                    {editing === null && form(draft, setDraft, false)}
                    <div>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    '/admin/custom-fields',
                                    { entity, ...payload(draft) },
                                    { preserveScroll: true, onSuccess: () => setDraft(empty) },
                                )
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
                                        {f.required && <Badge variant="outline">Obligatoire</Badge>}
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
                                                    required: f.required,
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
                                                Champ rempli sur {entity_info.records} : son type ne change plus et un choix utilisé ne peut pas être
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
