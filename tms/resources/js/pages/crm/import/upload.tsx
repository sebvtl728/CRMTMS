import { WizardPage, selectClass } from '@/components/crm/import-wizard';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FileSpreadsheet } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface UploadProps {
    targets: { value: string; label: string }[];
    profiles: { id: string; name: string; target: string }[];
    maxMegabytes: number;
}

const DESCRIPTIONS: Record<string, string> = {
    organisations: 'Une ligne par entreprise : raison sociale, SIRET, adresse, secteur…',
    people: 'Une ligne par personne : nom, prénom, e-mail, téléphone…',
    affiliations: 'Une ligne par interlocuteur : la personne et son entreprise sont créées ensemble et reliées.',
    notes: 'Les échanges passés (appels, rendez-vous…), rattachés à une fiche déjà présente.',
};

export default function ImportUpload({ targets, profiles, maxMegabytes }: UploadProps) {
    const form = useForm<{ file: File | null; target: string; profile_id: string; delimiter: string; encoding: string }>({
        file: null,
        target: '',
        profile_id: '',
        delimiter: '',
        encoding: '',
    });

    const profilesForTarget = profiles.filter((p) => p.target === form.data.target);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('crm.import.store'), { forceFormData: true });
    };

    return (
        <WizardPage
            step={1}
            title="Déposer le fichier"
            description="Excel (.xlsx) ou CSV. Vous pourrez vérifier chaque étape avant que quoi que ce soit soit créé."
        >
            <form onSubmit={submit} className="grid max-w-3xl gap-6">
                <fieldset className="grid gap-2">
                    <legend className="mb-1 text-sm font-medium">1. Que contient votre fichier ?</legend>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {targets.map((t) => (
                            <label
                                key={t.value}
                                className={
                                    'hover:bg-muted/30 flex cursor-pointer flex-col gap-1 rounded-xl border p-4 ' +
                                    (form.data.target === t.value ? 'border-primary ring-primary ring-1' : '')
                                }
                            >
                                <span className="flex items-center gap-2 font-medium">
                                    <input
                                        type="radio"
                                        name="target"
                                        value={t.value}
                                        checked={form.data.target === t.value}
                                        onChange={() => form.setData((d) => ({ ...d, target: t.value, profile_id: '' }))}
                                    />
                                    {t.label}
                                </span>
                                <span className="text-muted-foreground text-sm">{DESCRIPTIONS[t.value]}</span>
                            </label>
                        ))}
                    </div>
                    <InputError message={form.errors.target} />
                </fieldset>

                <div className="grid gap-2">
                    <Label htmlFor="file" className="text-sm font-medium">
                        2. Choisissez le fichier
                    </Label>
                    <div className="flex items-center gap-3 rounded-xl border border-dashed p-4">
                        <FileSpreadsheet className="text-muted-foreground h-6 w-6 shrink-0" aria-hidden />
                        <input
                            id="file"
                            type="file"
                            accept=".csv,.txt,.tsv,.xlsx"
                            onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                            className="text-sm"
                        />
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Formats : .xlsx, .csv. Taille maximale : {maxMegabytes} Mo. La première ligne du fichier doit contenir le nom des colonnes. Un
                        fichier .xls doit d&apos;abord être enregistré au format .xlsx.
                    </p>
                    <InputError message={form.errors.file} />
                </div>

                {profilesForTarget.length > 0 && (
                    <div className="grid gap-2">
                        <Label htmlFor="profile_id" className="text-sm font-medium">
                            3. Réutiliser un profil d&apos;import (facultatif)
                        </Label>
                        <select
                            id="profile_id"
                            className={selectClass}
                            value={form.data.profile_id}
                            onChange={(e) => form.setData('profile_id', e.target.value)}
                        >
                            <option value="">Aucun : je choisis les colonnes moi-même</option>
                            {profilesForTarget.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                        <p className="text-muted-foreground text-xs">
                            Un profil retient la correspondance des colonnes d&apos;un fichier déjà importé.
                        </p>
                        <InputError message={form.errors.profile_id} />
                    </div>
                )}

                <details className="rounded-xl border p-4 text-sm">
                    <summary className="cursor-pointer font-medium">Le fichier est mal lu ? Options avancées</summary>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <Label htmlFor="delimiter" className="text-xs">
                                Séparateur (CSV)
                            </Label>
                            <select
                                id="delimiter"
                                className={selectClass}
                                value={form.data.delimiter}
                                onChange={(e) => form.setData('delimiter', e.target.value)}
                            >
                                <option value="">Automatique</option>
                                <option value=";">Point-virgule ;</option>
                                <option value=",">Virgule ,</option>
                                <option value="tab">Tabulation</option>
                            </select>
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="encoding" className="text-xs">
                                Encodage (CSV)
                            </Label>
                            <select
                                id="encoding"
                                className={selectClass}
                                value={form.data.encoding}
                                onChange={(e) => form.setData('encoding', e.target.value)}
                            >
                                <option value="">Automatique</option>
                                <option value="UTF-8">UTF-8</option>
                                <option value="Windows-1252">Windows-1252 (Excel français)</option>
                            </select>
                        </div>
                        <InputError message={form.errors.delimiter ?? form.errors.encoding} />
                    </div>
                </details>

                <div>
                    <Button type="submit" disabled={form.processing || !form.data.file || !form.data.target}>
                        {form.processing ? 'Lecture du fichier…' : 'Continuer : associer les colonnes'}
                    </Button>
                </div>
            </form>
        </WizardPage>
    );
}
