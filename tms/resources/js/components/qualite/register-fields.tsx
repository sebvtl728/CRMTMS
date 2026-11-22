import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export interface Option {
    value: string;
    label: string;
}

export const textareaClass =
    'border-input bg-background focus:ring-ring w-full rounded-md border px-3 py-2 text-sm focus:ring-2 focus:outline-hidden';

/** A labelled input of a register form. */
export function Field({
    id,
    label,
    value,
    onChange,
    error,
    type = 'text',
    maxLength,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    type?: string;
    maxLength?: number;
}) {
    return (
        <div className="grid gap-1">
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} type={type} value={value} maxLength={maxLength} onChange={(e) => onChange(e.target.value)} />
            <InputError message={error} />
        </div>
    );
}

/** A labelled native select (empty = none). */
export function Choice({
    id,
    label,
    value,
    options,
    onChange,
    error,
    none,
}: {
    id: string;
    label: string;
    value: string;
    options: Option[];
    onChange: (value: string) => void;
    error?: string;
    none?: string;
}) {
    return (
        <div className="grid gap-1">
            <Label htmlFor={id}>{label}</Label>
            <select id={id} className={textareaClass} value={value} onChange={(e) => onChange(e.target.value)}>
                {none !== undefined && <option value="">{none}</option>}
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
            <InputError message={error} />
        </div>
    );
}

/** A labelled textarea. */
export function LongText({
    id,
    label,
    value,
    onChange,
    error,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (v: string) => void;
    error?: string;
}) {
    return (
        <div className="grid gap-1">
            <Label htmlFor={id}>{label}</Label>
            <textarea id={id} rows={3} className={textareaClass} value={value} onChange={(e) => onChange(e.target.value)} maxLength={5000} />
            <InputError message={error} />
        </div>
    );
}
