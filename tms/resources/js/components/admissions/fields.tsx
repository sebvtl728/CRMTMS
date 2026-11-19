import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDateTime, formatSize, type Option } from '@/lib/crm';
import { type CandidatureFileRow } from '@/types/admissions';
import { router } from '@inertiajs/react';
import { FileText, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';

const NONE = 'none';

export const textareaClass =
    'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden';

/** A select of options with an empty choice « — ». */
export function OptionSelect({
    id,
    label,
    value,
    options,
    onChange,
    error,
    disabled,
    noneLabel = '—',
}: {
    id: string;
    label: string;
    value: string | null;
    options: Option[];
    onChange: (value: string | null) => void;
    error?: string;
    disabled?: boolean;
    /** Label of the empty choice. */
    noneLabel?: string;
}) {
    return (
        <div className="grid gap-1">
            <Label htmlFor={id}>{label}</Label>
            <Select value={value ?? NONE} onValueChange={(v) => onChange(v === NONE ? null : v)} disabled={disabled}>
                <SelectTrigger id={id}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>{noneLabel}</SelectItem>
                    {options.map((o) => (
                        <SelectItem key={o.value} value={o.value}>
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <InputError message={error} />
        </div>
    );
}

/** A text (or date…) input with its label and error. */
export function TextField({
    id,
    label,
    value,
    onChange,
    error,
    type = 'text',
    disabled,
    placeholder,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    type?: string;
    disabled?: boolean;
    placeholder?: string;
}) {
    return (
        <div className="grid gap-1">
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} type={type} value={value} onChange={(e) => onChange(e.target.value)} disabled={disabled} placeholder={placeholder} />
            <InputError message={error} />
        </div>
    );
}

/**
 * The files of one kind of a candidature (positioning, contract, a custom
 * « file » field), with the upload of a new one when allowed.
 */
export function CandidatureFiles({
    candidatureId,
    kind,
    files,
    canUpdate,
    label = 'Ajouter un fichier',
    allowUpload = true,
}: {
    candidatureId: string;
    kind: string;
    files: CandidatureFileRow[];
    canUpdate: boolean;
    label?: string;
    /** false: the files are listed (and removable) but none is added here. */
    allowUpload?: boolean;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string | undefined>();
    const mine = files.filter((f) => f.kind === kind);

    const upload = (file: File) => {
        setError(undefined);
        router.post(
            `/admissions/candidatures/${candidatureId}/files`,
            { kind, file },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errs) => setError(errs.file ?? errs.kind),
            },
        );
    };

    return (
        <div className="grid gap-2">
            {mine.length > 0 && (
                <ul className="grid gap-1 text-sm">
                    {mine.map((f) => (
                        <li key={f.id} className="flex items-center justify-between gap-2 rounded-md border px-2 py-1">
                            <a href={f.href} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 hover:underline">
                                <FileText className="h-4 w-4" aria-hidden />
                                {f.name}
                                <span className="text-muted-foreground text-xs">
                                    {formatSize(f.size)} · {formatDateTime(f.created_at)}
                                </span>
                            </a>
                            {canUpdate && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    aria-label={`Supprimer ${f.name}`}
                                    onClick={() => router.delete(`/admissions/candidatures/${candidatureId}/files/${f.id}`, { preserveScroll: true })}
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {canUpdate && allowUpload && (
                <div>
                    <input
                        ref={input}
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png"
                        className="hidden"
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            if (file) upload(file);
                            e.target.value = '';
                        }}
                    />
                    <Button type="button" variant="outline" size="sm" onClick={() => input.current?.click()}>
                        <Upload className="h-4 w-4" />
                        {label}
                    </Button>
                    <p className="text-muted-foreground mt-1 text-xs">PDF, JPG ou PNG, 20 Mo au plus.</p>
                    <InputError message={error} />
                </div>
            )}
        </div>
    );
}
