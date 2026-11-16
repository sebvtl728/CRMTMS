import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useState } from 'react';

/** A person to create on the spot (dossier assistant, group enrolment): sent as new_person with its ref. */
export interface NewPerson {
    first_name: string;
    last_name: string;
    email: string;
    birth_date: string;
    employee: boolean;
    confirm_duplicate: boolean;
}

export const emptyPerson = (): NewPerson => ({ first_name: '', last_name: '', email: '', birth_date: '', employee: true, confirm_duplicate: false });

export function newPersonLabel(p: NewPerson | undefined): string {
    return `${[p?.first_name, p?.last_name].filter(Boolean).join(' ')} (à créer)`;
}

/**
 * The fields of a person to create (« Nouveau stagiaire »): the server
 * creates it with the batch, once per ref, the similar people refused
 * unless « Créer quand même ».
 */
export function NewPersonFields({
    index,
    value,
    employer,
    onChange,
    onCancel,
    onAdd,
}: {
    index: number;
    value: NewPerson;
    employer: string | null;
    onChange: (change: Partial<NewPerson>) => void;
    onCancel: () => void;
    onAdd: () => void;
}) {
    const [missing, setMissing] = useState(false);

    return (
        <fieldset className="grid gap-3 rounded-md border p-3 md:grid-cols-2">
            <legend className="px-1 text-sm font-medium">Nouveau stagiaire</legend>
            <div className="grid gap-1">
                <Label htmlFor={`new_first_name_${index}`}>Prénom</Label>
                <Input id={`new_first_name_${index}`} value={value.first_name} onChange={(e) => onChange({ first_name: e.target.value })} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`new_last_name_${index}`}>Nom *</Label>
                <Input id={`new_last_name_${index}`} value={value.last_name} onChange={(e) => onChange({ last_name: e.target.value })} />
                <InputError message={missing ? 'Indiquez le nom.' : undefined} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`new_email_${index}`}>E-mail</Label>
                <Input id={`new_email_${index}`} type="email" value={value.email} onChange={(e) => onChange({ email: e.target.value })} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`new_birth_date_${index}`}>Date de naissance</Label>
                <Input
                    id={`new_birth_date_${index}`}
                    type="date"
                    value={value.birth_date}
                    onChange={(e) => onChange({ birth_date: e.target.value })}
                />
            </div>
            {employer && (
                <label className="flex items-center gap-2 text-sm md:col-span-2">
                    <input type="checkbox" checked={value.employee} onChange={(e) => onChange({ employee: e.target.checked })} />
                    Salarié de {employer}
                </label>
            )}
            <div className="flex gap-2 md:col-span-2">
                <Button
                    type="button"
                    size="sm"
                    onClick={() => {
                        if (value.last_name.trim() === '') {
                            setMissing(true);

                            return;
                        }
                        onAdd();
                    }}
                >
                    Ajouter
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
                    Annuler
                </Button>
            </div>
        </fieldset>
    );
}
