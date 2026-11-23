import { Button } from '@/components/ui/button';
import { useEffect, useState } from 'react';

export type RncpSuggestion = { bpf_objective: string | null; nsf_code: string | null; competences_text: string };

type RncpInfo =
    | { found: false; loaded: boolean }
    | {
          found: true;
          code: string;
          title: string;
          level: number | null;
          state: 'active' | 'ending' | 'inactive';
          end_on: string | null;
          blocks: number;
          suggestion: RncpSuggestion;
      };

const STATE = {
    active: { label: 'active', className: 'border-green-300 bg-green-50 text-green-950 dark:border-green-800 dark:bg-green-950 dark:text-green-50' },
    ending: {
        label: 'fin d’enregistrement dans moins de 6 mois',
        className: 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-50',
    },
    inactive: { label: 'inactive', className: 'border-red-300 bg-red-50 text-red-950 dark:border-red-800 dark:bg-red-950 dark:text-red-50' },
} as const;

/** The RNCP fiche of the typed code (France compétences open data) and « Reprendre du RNCP ». */
export function RncpPanel({ code, onApply }: { code: string; onApply: (suggestion: RncpSuggestion) => void }) {
    const [info, setInfo] = useState<RncpInfo | null>(null);
    const clean = code.trim().toUpperCase();

    useEffect(() => {
        if (!/^RNCP\d{1,6}$/.test(clean)) {
            setInfo(null);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(route('catalogue.rncp', { code: clean }), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((json: RncpInfo | null) => setInfo(json))
                .catch(() => undefined);
        }, 300);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [clean]);

    if (info === null) return null;
    if (!info.found) {
        return (
            <p className="text-xs text-red-700 md:col-span-2 dark:text-red-400" role="status">
                {info.loaded ? `${clean} est inconnu du répertoire national (RNCP).` : 'Le répertoire RNCP n’est pas encore chargé sur ce serveur.'}
            </p>
        );
    }

    return (
        <div className={`grid gap-1 rounded-md border p-2 text-xs md:col-span-2 ${STATE[info.state].className}`} role="status">
            <span>
                <strong>{info.title}</strong>
                {info.level !== null && ` · niveau ${info.level}`} · certification {STATE[info.state].label}
                {info.end_on && ` · fin d’enregistrement le ${info.end_on}`}
                {info.blocks > 0 && ` · ${info.blocks} bloc(s) de compétences`}
            </span>
            <div>
                <Button type="button" size="sm" variant="outline" onClick={() => onApply(info.suggestion)}>
                    Reprendre du RNCP
                </Button>{' '}
                <span className="opacity-80">objectif BPF, spécialité NSF et grille de compétences, seulement s’ils sont vides</span>
            </div>
        </div>
    );
}
