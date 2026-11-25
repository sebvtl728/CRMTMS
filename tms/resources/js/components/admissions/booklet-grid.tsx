import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { type Grid } from './booklet-types';

const LEVELS = ['A', 'ECA', 'NA'] as const;

/** The competence grid: A / ECA / NA on the centre side (editable), the company side as the tutor filled it. */
export function BookletGrid({ candidatureId, grid }: { candidatureId: string; grid: Grid }) {
    const set = (key: string, level: string | null) =>
        router.put(route('admissions.booklet.grid', candidatureId), { key, level: level ?? '' }, { preserveScroll: true, only: ['grid'] });

    if (grid.types.length === 0) {
        return <p className="text-muted-foreground text-sm">La formation n'a pas de grille de compétences (onglet « Compétences » de la fiche formation).</p>;
    }

    return (
        <div className="flex flex-col gap-4">
            <p className="text-muted-foreground text-sm">A : acquis - ECA : en cours d'acquisition - NA : non acquis. La colonne entreprise est remplie par le tuteur.</p>
            {grid.orphans > 0 && (
                <p className="text-sm text-amber-700 dark:text-amber-400">{grid.orphans} évaluation(s) portent sur des savoir-faire retirés de la formation.</p>
            )}
            <div className="overflow-x-auto rounded-md border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-3 py-2 font-medium">Savoir-faire</th>
                            <th className="px-3 py-2 font-medium">Centre</th>
                            <th className="px-3 py-2 font-medium">Entreprise</th>
                        </tr>
                    </thead>
                    <tbody>
                        {grid.types.map((at, i) => [
                            <tr key={`at-${i}`} className="bg-muted/30 border-t">
                                <td colSpan={3} className="px-3 py-2 font-semibold">
                                    Activité type {i + 1} - {at.title}
                                </td>
                            </tr>,
                            ...at.competences.flatMap((cp, j) => [
                                <tr key={`cp-${i}-${j}`} className="border-t">
                                    <td colSpan={3} className="px-3 py-2 font-medium italic">
                                        {cp.title}
                                    </td>
                                </tr>,
                                ...cp.skills.map((skill) => (
                                    <tr key={skill.key} className="border-t">
                                        <td className="px-3 py-2 pl-6">{skill.title}</td>
                                        <td className="px-3 py-2">
                                            <div className="flex gap-1" role="group" aria-label={`Centre : ${skill.title}`}>
                                                {LEVELS.map((level) => (
                                                    <Button
                                                        key={level}
                                                        type="button"
                                                        size="sm"
                                                        variant={skill.centre === level ? 'default' : 'outline'}
                                                        aria-pressed={skill.centre === level}
                                                        onClick={() => set(skill.key, skill.centre === level ? null : level)}
                                                    >
                                                        {level}
                                                    </Button>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">{skill.company ?? <span className="text-muted-foreground">-</span>}</td>
                                    </tr>
                                )),
                            ]),
                        ])}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
