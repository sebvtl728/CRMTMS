import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, EyeOff, GripVertical, Pencil, Plus, Power, Trash2 } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

type ReferenceMeta = {
    probability?: number;
    outcome?: 'won' | 'lost' | null;
    color?: string | null;
    planning?: 'meeting' | 'call' | null;
    quote_stage?: boolean;
    force_majeure?: boolean;
    default_for?: 'organisation' | 'person' | null;
};

/** Place of an action type in the CRM planning (meta.planning). */
const PLANNING_KINDS = { meeting: 'Rendez-vous', call: 'Appel' } as const;

/** Contractant a funding type funds by default (meta.default_for). */
const DEFAULT_FOR = { organisation: 'Entreprise contractante', person: 'Particulier contractant' } as const;

interface ReferenceItem {
    id: string;
    code: string;
    label: string;
    position: number;
    is_system: boolean;
    is_active: boolean;
    in_use: boolean;
    meta: ReferenceMeta;
}

interface ReferenceListData {
    key: string;
    label: string;
    has_probability: boolean;
    has_planning: boolean;
    has_quote_stage: boolean;
    has_force_majeure: boolean;
    has_default_for: boolean;
    items: ReferenceItem[];
}

interface ReferencesIndexProps {
    lists: Record<string, ReferenceListData>;
    status?: string;
}

interface EditorState {
    item: ReferenceItem | null; // null = creation
    label: string;
    probability: string;
    color: string; // '' = none
    planning: string; // '' = not in the planning
    quoteStage: boolean;
    forceMajeure: boolean;
    defaultFor: string; // '' = none
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Référentiels', href: '/admin/references' }];

export default function ReferencesIndex({ lists, status }: ReferencesIndexProps) {
    const keys = Object.keys(lists);
    const [current, setCurrent] = useState(keys[0]);
    const list = lists[current];
    const errors = usePage().props.errors as Record<string, string>;

    const [order, setOrder] = useState<ReferenceItem[]>(list.items);
    const [dragId, setDragId] = useState<string | null>(null);
    const [editor, setEditor] = useState<EditorState | null>(null);
    const [processing, setProcessing] = useState(false);

    // Server state wins whenever it changes (after a save, a reorder or a tab switch).
    useEffect(() => setOrder(list.items), [list.items]);

    const persistOrder = (next: ReferenceItem[]) => {
        setOrder(next);
        router.post(route('admin.references.reorder', current), { ids: next.map((i) => i.id) }, { preserveScroll: true, preserveState: true });
    };

    const move = (from: number, to: number) => {
        if (from === to || to < 0 || to >= order.length) return;
        const next = [...order];
        next.splice(to, 0, next.splice(from, 1)[0]);
        persistOrder(next);
    };

    const openEditor = (item: ReferenceItem | null) =>
        setEditor({
            item,
            label: item?.label ?? '',
            probability: item?.meta.probability !== undefined ? String(item.meta.probability) : '',
            color: item?.meta.color ?? '',
            planning: item?.meta.planning ?? '',
            quoteStage: item?.meta.quote_stage === true,
            forceMajeure: item?.meta.force_majeure === true,
            defaultFor: item?.meta.default_for ?? '',
        });

    const closing = editor?.item?.meta.outcome != null; // won / lost: fixed probability

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!editor) return;

        const meta: ReferenceMeta = { color: editor.color || null };
        if (list.has_probability && editor.probability !== '') meta.probability = Number(editor.probability);
        if (list.has_planning) meta.planning = editor.planning === '' ? null : (editor.planning as 'meeting' | 'call');
        if (list.has_quote_stage && !closing) meta.quote_stage = editor.quoteStage;
        if (list.has_force_majeure) meta.force_majeure = editor.forceMajeure;
        if (list.has_default_for) meta.default_for = editor.defaultFor === '' ? null : (editor.defaultFor as 'organisation' | 'person');
        const payload = { label: editor.label, meta };
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => setEditor(null),
        };

        if (editor.item) {
            router.put(route('admin.references.update', [current, editor.item.id]), payload, options);
        } else {
            router.post(route('admin.references.store', current), payload, options);
        }
    };

    const toggleActive = (item: ReferenceItem) =>
        router.put(
            route('admin.references.update', [current, item.id]),
            { label: item.label, is_active: !item.is_active, meta: item.meta },
            { preserveScroll: true },
        );

    const remove = (item: ReferenceItem) => {
        if (!window.confirm(`Supprimer la valeur « ${item.label} » ?`)) return;
        router.delete(route('admin.references.destroy', [current, item.id]), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Référentiels" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Référentiels"
                    description="Listes de valeurs de votre organisme, utilisées dans les fiches, le pipeline et les actions"
                />

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}
                {errors.item && (
                    <div
                        role="alert"
                        className="text-destructive rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm dark:border-red-900 dark:bg-red-950"
                    >
                        {errors.item}
                    </div>
                )}
                {errors.ids && <InputError message={errors.ids} />}

                <div role="tablist" aria-label="Listes" className="flex flex-wrap gap-1">
                    {keys.map((key) => (
                        <button
                            key={key}
                            role="tab"
                            type="button"
                            aria-selected={key === current}
                            onClick={() => setCurrent(key)}
                            className={cn(
                                'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                key === current ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/70',
                            )}
                        >
                            {lists[key].label}
                        </button>
                    ))}
                </div>

                <section role="tabpanel" className="flex flex-col gap-3">
                    <div className="flex items-center justify-between gap-4">
                        <p className="text-muted-foreground text-sm">
                            Glissez-déposez les lignes (ou utilisez les flèches) pour changer l'ordre d'affichage.
                        </p>
                        <Button onClick={() => openEditor(null)}>
                            <Plus className="h-4 w-4" />
                            Ajouter une valeur
                        </Button>
                    </div>

                    <ul className="divide-y rounded-xl border">
                        {order.map((item, index) => (
                            <li
                                key={item.id}
                                draggable
                                onDragStart={() => setDragId(item.id)}
                                onDragEnd={() => setDragId(null)}
                                onDragOver={(e) => e.preventDefault()}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    const from = order.findIndex((i) => i.id === dragId);
                                    setDragId(null);
                                    if (from >= 0) move(from, index);
                                }}
                                className={cn(
                                    'flex items-center gap-3 px-3 py-2',
                                    dragId === item.id && 'opacity-40',
                                    !item.is_active && 'bg-muted/40',
                                )}
                            >
                                <GripVertical className="text-muted-foreground h-4 w-4 shrink-0 cursor-grab" aria-hidden />
                                {item.meta.color && (
                                    <span className="h-3 w-3 shrink-0 rounded-full border" style={{ backgroundColor: item.meta.color }} aria-hidden />
                                )}
                                <div className="min-w-0 flex-1">
                                    <span className={cn('font-medium', !item.is_active && 'text-muted-foreground line-through')}>{item.label}</span>
                                    <span className="text-muted-foreground ml-2 text-xs">{item.code}</span>
                                </div>
                                {list.has_probability && item.meta.probability !== undefined && (
                                    <Badge variant="outline">{item.meta.probability} %</Badge>
                                )}
                                {list.has_quote_stage && item.meta.quote_stage && <Badge variant="outline">Étape du devis envoyé</Badge>}
                                {list.has_planning && item.meta.planning && (
                                    <Badge variant="outline">Planning : {PLANNING_KINDS[item.meta.planning]}</Badge>
                                )}
                                {list.has_force_majeure && item.meta.force_majeure && <Badge variant="outline">Force majeure</Badge>}
                                {list.has_default_for && item.meta.default_for && (
                                    <Badge variant="outline">Par défaut : {DEFAULT_FOR[item.meta.default_for]}</Badge>
                                )}
                                {item.is_system && <Badge variant="secondary">Système</Badge>}
                                {item.in_use && <Badge variant="secondary">Utilisée</Badge>}
                                {!item.is_active && (
                                    <Badge variant="outline">
                                        <EyeOff className="mr-1 h-3 w-3" />
                                        Inactive
                                    </Badge>
                                )}
                                <div className="flex shrink-0 items-center">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => move(index, index - 1)}
                                        disabled={index === 0}
                                        aria-label={`Monter ${item.label}`}
                                    >
                                        <ArrowUp className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => move(index, index + 1)}
                                        disabled={index === order.length - 1}
                                        aria-label={`Descendre ${item.label}`}
                                    >
                                        <ArrowDown className="h-4 w-4" />
                                    </Button>
                                    <Button variant="ghost" size="icon" onClick={() => openEditor(item)} aria-label={`Modifier ${item.label}`}>
                                        <Pencil className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => toggleActive(item)}
                                        disabled={item.is_system && item.is_active}
                                        title={item.is_system ? 'Valeur système : désactivation impossible' : undefined}
                                        aria-label={`${item.is_active ? 'Désactiver' : 'Réactiver'} ${item.label}`}
                                    >
                                        <Power className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => remove(item)}
                                        disabled={item.is_system || item.in_use}
                                        title={
                                            item.is_system
                                                ? 'Valeur système : suppression impossible'
                                                : item.in_use
                                                  ? 'Valeur utilisée : désactivez-la plutôt'
                                                  : undefined
                                        }
                                        aria-label={`Supprimer ${item.label}`}
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </div>
                            </li>
                        ))}
                        {order.length === 0 && <li className="text-muted-foreground px-4 py-6 text-center text-sm">Aucune valeur.</li>}
                    </ul>
                </section>
            </div>

            <Dialog open={editor !== null} onOpenChange={(open) => !open && setEditor(null)}>
                <DialogContent>
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>{editor?.item ? 'Modifier la valeur' : 'Ajouter une valeur'}</DialogTitle>
                            <DialogDescription>{list.label}</DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-2">
                            <Label htmlFor="ref-label">Libellé</Label>
                            <Input
                                id="ref-label"
                                value={editor?.label ?? ''}
                                onChange={(e) => setEditor((s) => s && { ...s, label: e.target.value })}
                                required
                                autoFocus
                            />
                            <InputError message={errors.label} />
                        </div>

                        {list.has_probability && (
                            <div className="grid gap-2">
                                <Label htmlFor="ref-probability">Probabilité de gain (%)</Label>
                                <Input
                                    id="ref-probability"
                                    type="number"
                                    min={0}
                                    max={100}
                                    step={1}
                                    value={editor?.probability ?? ''}
                                    onChange={(e) => setEditor((s) => s && { ...s, probability: e.target.value })}
                                    disabled={closing}
                                    required={!closing}
                                />
                                {closing && <p className="text-muted-foreground text-xs">Étape de clôture : probabilité fixe.</p>}
                                <InputError message={errors['meta.probability']} />
                            </div>
                        )}

                        {list.has_planning && (
                            <div className="grid gap-2">
                                <Label htmlFor="ref-planning">Planning commercial</Label>
                                <select
                                    id="ref-planning"
                                    className="border-input bg-background focus:ring-ring h-9 w-full rounded-md border px-3 text-sm focus:ring-2 focus:outline-hidden"
                                    value={editor?.planning ?? ''}
                                    onChange={(e) => setEditor((s) => s && { ...s, planning: e.target.value })}
                                >
                                    <option value="">Non affiché</option>
                                    <option value="meeting">Rendez-vous (planning et compte rendu de RDV)</option>
                                    <option value="call">Appel (planning, s'il a une échéance)</option>
                                </select>
                                <InputError message={errors['meta.planning']} />
                            </div>
                        )}

                        {list.has_quote_stage && !closing && (
                            <div className="grid gap-1">
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={editor?.quoteStage ?? false}
                                        onChange={(e) => setEditor((s) => s && { ...s, quoteStage: e.target.checked })}
                                    />
                                    Étape d'un devis envoyé
                                </label>
                                <p className="text-muted-foreground text-xs">
                                    À l'envoi d'un devis, l'opportunité passe à cette étape si elle n'y est pas encore. Une seule étape : la cocher la retire des autres.
                                </p>
                                <InputError message={errors['meta.quote_stage']} />
                            </div>
                        )}

                        {list.has_force_majeure && (
                            <div className="grid gap-1">
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={editor?.forceMajeure ?? false}
                                        onChange={(e) => setEditor((s) => s && { ...s, forceMajeure: e.target.checked })}
                                    />
                                    Force majeure
                                </label>
                                <p className="text-muted-foreground text-xs">Un abandon pour ce motif n'entraîne jamais de dédit.</p>
                                <InputError message={errors['meta.force_majeure']} />
                            </div>
                        )}

                        {list.has_default_for && (
                            <div className="grid gap-1">
                                <Label htmlFor="ref-default-for">Financement initial d'un dossier</Label>
                                <select
                                    id="ref-default-for"
                                    className="border-input bg-background focus:ring-ring h-9 w-full rounded-md border px-3 text-sm focus:ring-2 focus:outline-hidden"
                                    value={editor?.defaultFor ?? ''}
                                    onChange={(e) => setEditor((s) => s && { ...s, defaultFor: e.target.value })}
                                >
                                    <option value="">Jamais par défaut</option>
                                    <option value="organisation">Par défaut quand le contractant est une entreprise</option>
                                    <option value="person">Par défaut quand le contractant est un particulier</option>
                                </select>
                                <p className="text-muted-foreground text-xs">
                                    Un dossier créé depuis un devis accepté reçoit un financement de ce type pour tout son prix. Une seule valeur par cas
                                    ; aucune : pas de financement initial.
                                </p>
                                <InputError message={errors['meta.default_for']} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="ref-color">Couleur (facultative)</Label>
                            <div className="flex items-center gap-3">
                                <Input
                                    id="ref-color"
                                    type="color"
                                    className="h-9 w-16 p-1"
                                    value={editor?.color || '#6b7280'}
                                    onChange={(e) => setEditor((s) => s && { ...s, color: e.target.value })}
                                />
                                {editor?.color && (
                                    <Button type="button" variant="ghost" size="sm" onClick={() => setEditor((s) => s && { ...s, color: '' })}>
                                        Retirer la couleur
                                    </Button>
                                )}
                            </div>
                            <InputError message={errors['meta.color']} />
                        </div>

                        {editor?.item && <p className="text-muted-foreground text-xs">Code : {editor.item.code} (non modifiable)</p>}

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setEditor(null)}>
                                Annuler
                            </Button>
                            <Button type="submit" disabled={processing}>
                                Enregistrer
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
