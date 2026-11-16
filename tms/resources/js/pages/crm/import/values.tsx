import { AbandonButton, WizardPage, selectClass, type BatchSummary } from '@/components/crm/import-wizard';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface Block {
    field: string;
    label: string;
    kind: 'reference' | 'status' | 'responsible';
    canCreate: boolean;
    options: { value: string; label: string }[];
    values: string[];
    more: number;
    choices: Record<string, string>;
}

interface ValuesProps {
    batch: BatchSummary;
    blocks: Block[];
    profileName: string | null;
}

const CREATE = '__create__';
const EMPTY = '__empty__';

const BLOCK_HELP: Record<Block['kind'], string> = {
    reference: "Ces valeurs ne figurent pas encore dans la liste de l'application.",
    status: 'Ces statuts ne sont pas reconnus (Prospect, Client ou Inactif).',
    responsible: 'Ces responsables ne correspondent à aucun utilisateur actif (nom ou e-mail).',
};

export default function ImportValues({ batch, blocks, profileName }: ValuesProps) {
    const form = useForm<{ values: Record<string, Record<string, string>>; profile_name: string }>({
        values: Object.fromEntries(blocks.map((b) => [b.field, { ...b.choices }])),
        profile_name: profileName ?? '',
    });

    const setChoice = (field: string, value: string, action: string) =>
        form.setData('values', { ...form.data.values, [field]: { ...form.data.values[field], [value]: action } });

    const setAll = (block: Block, action: string) =>
        form.setData('values', { ...form.data.values, [block.field]: Object.fromEntries(block.values.map((v) => [v, action])) });

    const open = blocks.reduce((n, b) => n + b.values.filter((v) => !form.data.values[b.field]?.[v]).length, 0);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(route('crm.import.values.save', batch.id));
    };

    return (
        <WizardPage
            step={3}
            title="Associer les valeurs"
            description="Certaines valeurs de votre fichier n'existent pas dans l'application. Pour chacune, choisissez quoi faire : rien n'est créé avant la fin de l'étape suivante."
        >
            <form onSubmit={submit} className="grid max-w-4xl gap-6">
                {blocks.map((block) => (
                    <section key={block.field} aria-label={block.label} className="rounded-xl border">
                        <header className="flex flex-wrap items-center justify-between gap-2 border-b p-3">
                            <div>
                                <h3 className="font-medium">{block.label}</h3>
                                <p className="text-muted-foreground text-xs">{BLOCK_HELP[block.kind]}</p>
                            </div>
                            <div className="flex gap-2">
                                {block.canCreate && (
                                    <Button type="button" variant="outline" size="sm" onClick={() => setAll(block, CREATE)}>
                                        Tout créer
                                    </Button>
                                )}
                                <Button type="button" variant="outline" size="sm" onClick={() => setAll(block, EMPTY)}>
                                    Tout laisser vide
                                </Button>
                            </div>
                        </header>
                        <ul className="divide-y">
                            {block.values.map((value, i) => (
                                <li key={value} className="grid gap-2 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,22rem)] md:items-center md:gap-4">
                                    <Label htmlFor={`${block.field}-${i}`} className="font-medium break-words">
                                        « {value} »
                                    </Label>
                                    <select
                                        id={`${block.field}-${i}`}
                                        className={selectClass}
                                        value={form.data.values[block.field]?.[value] ?? ''}
                                        onChange={(e) => setChoice(block.field, value, e.target.value)}
                                    >
                                        <option value="">Choisir…</option>
                                        {block.canCreate && <option value={CREATE}>Créer la valeur « {value} »</option>}
                                        <option value={EMPTY}>Laisser vide</option>
                                        <optgroup
                                            label={
                                                block.kind === 'responsible'
                                                    ? 'Associer à un utilisateur'
                                                    : block.kind === 'status'
                                                      ? 'Associer au statut'
                                                      : 'Associer à une valeur existante'
                                            }
                                        >
                                            {block.options.map((o) => (
                                                <option key={o.value} value={o.value}>
                                                    {o.label}
                                                </option>
                                            ))}
                                        </optgroup>
                                    </select>
                                </li>
                            ))}
                        </ul>
                        {block.more > 0 && (
                            <p className="text-muted-foreground border-t p-3 text-xs">
                                Et {block.more} autres valeurs non affichées : elles resteront refusées. Regroupez-les dans le fichier avant de
                                réimporter.
                            </p>
                        )}
                    </section>
                ))}
                <InputError message={form.errors.values} />

                <div className="grid gap-2 rounded-xl border p-4">
                    <Label htmlFor="profile_name" className="font-medium">
                        Enregistrer ces choix dans un profil (facultatif)
                    </Label>
                    <Input
                        id="profile_name"
                        value={form.data.profile_name}
                        onChange={(e) => form.setData('profile_name', e.target.value)}
                        maxLength={100}
                        className="max-w-sm"
                    />
                    <p className="text-muted-foreground text-xs">Le profil retient aussi la correspondance des colonnes.</p>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Analyse…' : 'Continuer : voir la simulation'}
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={route('crm.import.mapping', batch.id)}>Retour aux colonnes</Link>
                    </Button>
                    <AbandonButton batchId={batch.id} />
                    {open > 0 && (
                        <span className="text-muted-foreground text-sm">{open} valeur(s) sans choix : les lignes concernées seront refusées.</span>
                    )}
                </div>
            </form>
        </WizardPage>
    );
}
