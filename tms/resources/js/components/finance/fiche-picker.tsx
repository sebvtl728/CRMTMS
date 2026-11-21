import { Input } from '@/components/ui/input';
import { useEffect, useState } from 'react';

export interface PickedFiche {
    type: 'organisation' | 'person';
    id: string;
    label: string;
}

/**
 * Searches the fiches of the organisme (finance/clients: at least 2
 * characters); `onlyOrganisations`: entreprises only (co-contractors).
 */
export function FichePicker({
    id,
    value,
    onChange,
    onlyOrganisations = false,
    placeholder = 'Rechercher une entreprise ou une personne…',
}: {
    id: string;
    value: PickedFiche | null;
    onChange: (fiche: PickedFiche | null) => void;
    onlyOrganisations?: boolean;
    placeholder?: string;
}) {
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<PickedFiche[]>([]);

    useEffect(() => {
        if (query.trim().length < 2) {
            setFound([]);
            return;
        }
        const timer = setTimeout(() => {
            const params = new URLSearchParams({ q: query, ...(onlyOrganisations ? { only: 'organisation' } : {}) });
            fetch(`/finance/clients?${params.toString()}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((data: { clients: PickedFiche[] }) => setFound(data.clients));
        }, 250);

        return () => clearTimeout(timer);
    }, [query, onlyOrganisations]);

    if (value) {
        return (
            <div className="flex items-center justify-between gap-2 rounded-md border px-2 py-1.5 text-sm">
                <span>{value.label}</span>
                <button type="button" className="text-muted-foreground text-xs underline" onClick={() => onChange(null)}>
                    Changer
                </button>
            </div>
        );
    }

    return (
        <div className="relative">
            <Input id={id} value={query} placeholder={placeholder} autoComplete="off" onChange={(e) => setQuery(e.target.value)} />
            {found.length > 0 && (
                <ul className="bg-popover absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-md border text-sm shadow">
                    {found.map((f) => (
                        <li key={`${f.type}:${f.id}`}>
                            <button
                                type="button"
                                className="hover:bg-muted w-full px-2 py-1.5 text-left"
                                onClick={() => {
                                    onChange(f);
                                    setQuery('');
                                    setFound([]);
                                }}
                            >
                                {f.label}
                                {!onlyOrganisations && (
                                    <span className="text-muted-foreground ml-1 text-xs">{f.type === 'organisation' ? 'entreprise' : 'personne'}</span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
