import { WizardPage, selectClass, type BatchSummary } from '@/components/crm/import-wizard';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Link, useForm } from '@inertiajs/react';
import { AlertTriangle, Check } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';

interface Field {
    value: string;
    label: string;
    required: boolean;
}

type Reason = 'memory' | 'header' | 'similar' | 'content';

const REASON_LABELS: Record<Reason, string> = {
    memory: 'Proposé : choix précédent',
    header: 'Proposé d’après l’en-tête',
    similar: 'Proposé d’après l’en-tête',
    content: 'Proposé d’après le contenu',
};

interface MappingProps {
    batch: BatchSummary;
    headers: string[];
    preview: string[][];
    warnings: string[];
    info: string;
    fields: Field[];
    map: Record<string, string>;
    reasons: Partial<Record<string, Reason>>;
    identifierFields: string[];
    sourceSystem: string | null;
    profileName: string | null;
    error: string | null;
}

export default function ImportMapping({
    batch,
    headers,
    preview,
    warnings,
    info,
    fields,
    map,
    reasons,
    identifierFields,
    sourceSystem,
    profileName,
    error,
}: MappingProps) {
    const form = useForm<{ map: Record<string, string>; source_system: string; profile_name: string }>({
        map: Object.fromEntries(headers.map((h) => [h, map[h] ?? ''])),
        source_system: sourceSystem ?? '',
        profile_name: profileName ?? '',
    });

    const used = new Set(Object.values(form.data.map).filter(Boolean));
    const required = fields.filter((f) => f.required);
    const missing = required.filter((f) => !used.has(f.value));
    const identifierMapped = identifierFields.some((f) => used.has(f));
    const mappedCount = used.size;

    // A proposal's reason is shown until the user changes that column.
    const [changed, setChanged] = useState<Set<string>>(() => new Set());

    const setField = (column: string, field: string) => {
        form.setData('map', { ...form.data.map, [column]: field });
        setChanged((previous) => new Set(previous).add(column));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(route('crm.import.mapping.save', batch.id));
    };

    return (
        <WizardPage
            step={2}
            title="Associer les colonnes"
            description={`Pour chaque colonne de « ${batch.original_name} », indiquez à quelle information elle correspond. Les colonnes laissées sur « Ignorer » ne sont pas importées.`}
        >
            <form onSubmit={submit} className="grid max-w-4xl gap-5">
                <p className="text-muted-foreground text-sm">
                    {info} Contenu : {batch.target.label}. L&apos;exemple montre les 5 premières lignes du fichier.
                </p>

                {error && (
                    <Alert variant="destructive">
                        <AlertTriangle className="h-4 w-4" />
                        <AlertTitle>La simulation précédente a échoué</AlertTitle>
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                {warnings.length > 0 && (
                    <Alert>
                        <AlertTriangle className="h-4 w-4" />
                        <AlertTitle>À savoir sur ce fichier</AlertTitle>
                        <AlertDescription>
                            <ul className="list-disc pl-4">
                                {warnings.map((w) => (
                                    <li key={w}>{w}</li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                <section aria-label="Champs obligatoires" className="flex flex-wrap items-center gap-2 rounded-xl border p-3 text-sm">
                    <span className="font-medium">Obligatoire :</span>
                    {required.map((f) => (
                        <span
                            key={f.value}
                            className={
                                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 ' +
                                (used.has(f.value)
                                    ? 'border-green-600/40 text-green-700 dark:text-green-400'
                                    : 'border-red-600/50 font-medium text-red-600 dark:text-red-400')
                            }
                        >
                            {used.has(f.value) ? (
                                <Check className="h-3.5 w-3.5" aria-hidden />
                            ) : (
                                <AlertTriangle className="h-3.5 w-3.5" aria-hidden />
                            )}
                            {f.label}
                            {!used.has(f.value) && ' : à associer'}
                        </span>
                    ))}
                    {batch.target.value === 'notes' && (
                        <span className="text-muted-foreground">+ SIRET, e-mail ou identifiant source pour retrouver la fiche</span>
                    )}
                </section>
                <InputError message={form.errors.map} />

                <ul className="divide-y rounded-xl border">
                    {headers.map((header, i) => {
                        const value = form.data.map[header] ?? '';
                        const examples = preview.map((row) => row[i]).filter((v) => v !== undefined && v !== '');
                        const reason = changed.has(header) ? undefined : reasons[header];

                        return (
                            <li key={header} className="grid gap-2 p-3 md:grid-cols-[minmax(0,1fr)_minmax(0,18rem)] md:items-center md:gap-4">
                                <div className="min-w-0">
                                    <Label htmlFor={`col-${i}`} className="font-medium break-words">
                                        {header}
                                    </Label>
                                    <p className="text-muted-foreground mt-0.5 truncate text-xs" title={examples.join(' · ')}>
                                        {examples.length > 0 ? examples.join(' · ') : 'Colonne vide dans les 5 premières lignes'}
                                    </p>
                                </div>
                                <div className="grid gap-1">
                                    <select
                                        id={`col-${i}`}
                                        className={selectClass}
                                        value={value}
                                        onChange={(e) => setField(header, e.target.value)}
                                        aria-describedby={reason ? `col-${i}-reason` : undefined}
                                    >
                                        <option value="">Ignorer</option>
                                        {fields.map((f) => (
                                            <option key={f.value} value={f.value} disabled={used.has(f.value) && value !== f.value}>
                                                {f.label}
                                                {f.required ? ' (obligatoire)' : ''}
                                                {used.has(f.value) && value !== f.value ? ' : déjà utilisé' : ''}
                                            </option>
                                        ))}
                                    </select>
                                    {reason && (
                                        <p id={`col-${i}-reason`} className="text-muted-foreground text-xs">
                                            {REASON_LABELS[reason]}
                                        </p>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>

                {identifierMapped && (
                    <div className="grid gap-2 rounded-xl border p-4">
                        <Label htmlFor="source_system" className="font-medium">
                            Logiciel d&apos;origine des identifiants
                        </Label>
                        <Input
                            id="source_system"
                            value={form.data.source_system}
                            onChange={(e) => form.setData('source_system', e.target.value)}
                            placeholder="Ex. Formevol"
                            maxLength={50}
                            className="max-w-sm"
                        />
                        <p className="text-muted-foreground text-xs">
                            Une colonne d&apos;identifiant est associée : indiquez de quel logiciel elle vient. En réimportant plus tard, les fiches
                            déjà importées seront mises à jour au lieu d&apos;être dupliquées.
                        </p>
                        <InputError message={form.errors.source_system} />
                    </div>
                )}

                <div className="grid gap-2 rounded-xl border p-4">
                    <Label htmlFor="profile_name" className="font-medium">
                        Enregistrer cette correspondance comme profil (facultatif)
                    </Label>
                    <Input
                        id="profile_name"
                        value={form.data.profile_name}
                        onChange={(e) => form.setData('profile_name', e.target.value)}
                        placeholder="Ex. Export Formevol entreprises"
                        maxLength={100}
                        className="max-w-sm"
                    />
                    <p className="text-muted-foreground text-xs">Au prochain fichier du même format, les colonnes seront déjà associées.</p>
                    <InputError message={form.errors.profile_name} />
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" disabled={form.processing || missing.length > 0 || mappedCount === 0}>
                        {form.processing ? 'Analyse…' : 'Continuer : analyser le fichier'}
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={route('crm.import.create')}>Choisir un autre fichier</Link>
                    </Button>
                    {missing.length > 0 && (
                        <span className="text-sm text-red-600 dark:text-red-400">
                            Associez d&apos;abord : {missing.map((f) => f.label).join(', ')}.
                        </span>
                    )}
                </div>
            </form>
        </WizardPage>
    );
}
