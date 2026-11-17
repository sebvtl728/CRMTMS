import { pickOnEnter, SearchHint } from '@/components/sessions/search-hits';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useEffect, useState } from 'react';

export interface Picked {
    id: string;
    label: string;
}

/**
 * Search in the CRM (identity only, whole organisme): entreprises or people,
 * via the dossier endpoints. The text typed is only a search: a result must
 * be clicked (or Enter on the result of that name, or the only one).
 */
export function FichePicker({
    id,
    kind,
    organisationId,
    value,
    onPick,
    placeholder,
    searchUrl,
}: {
    id: string;
    kind: 'organisation' | 'person';
    organisationId?: string;
    value: Picked | null;
    onPick: (p: Picked | null) => void;
    placeholder: string;
    /** Another search endpoint answering the same shape (entreprises / personnes). */
    searchUrl?: string;
}) {
    const [term, setTerm] = useState('');
    const [hits, setHits] = useState<Picked[]>([]);
    const [searched, setSearched] = useState(false);

    useEffect(() => {
        setSearched(false);
        if (term.trim().length < 2) {
            setHits([]);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(() => {
            const params: Record<string, string> = { q: term };
            if (organisationId) params.organisation = organisationId;
            fetch(`${searchUrl ?? route(kind === 'organisation' ? 'dossiers.organisations' : 'dossiers.people')}?${new URLSearchParams(params)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((json) => {
                    setSearched(true);
                    if (kind === 'organisation') {
                        setHits((json?.entreprises ?? []) as Picked[]);
                    } else {
                        const people = (json?.personnes ?? []) as {
                            id: string;
                            last_name: string;
                            first_name: string | null;
                            birth_date: string | null;
                            organisation_label: string | null;
                        }[];
                        setHits(
                            people.map((p) => ({
                                id: p.id,
                                label: [[p.first_name, p.last_name].filter(Boolean).join(' '), p.organisation_label].filter(Boolean).join(' · '),
                            })),
                        );
                    }
                })
                .catch(() => undefined);
        }, 250);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, kind, organisationId, searchUrl]);

    if (value) {
        return (
            <div className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm">
                <span>{value.label}</span>
                <Button type="button" variant="ghost" size="sm" onClick={() => onPick(null)}>
                    Changer
                </Button>
            </div>
        );
    }

    return (
        <div className="grid gap-1">
            <Input
                id={id}
                type="search"
                value={term}
                onChange={(e) => setTerm(e.target.value)}
                onKeyDown={(e) => pickOnEnter(e, term, hits, (h) => h.label, onPick)}
                placeholder={placeholder}
            />
            {hits.length > 0 && (
                <ul className="grid gap-1 rounded-md border p-1 text-sm">
                    {hits.map((h) => (
                        <li key={h.id}>
                            <button type="button" className="hover:bg-muted w-full rounded px-2 py-1 text-left" onClick={() => onPick(h)}>
                                {h.label}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <SearchHint term={term} count={hits.length} searched={searched} />
        </div>
    );
}
