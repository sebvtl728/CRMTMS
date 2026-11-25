import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { PLACE_LABEL, signLabel, textareaClass, WEEKS, type BookletMonthRow, type BookletPage, type Place, type Signer, type Weeks } from './booklet-types';

function SignBadge({ who, state }: { who: string; state: BookletPage['apprentice'] }) {
    const done = state.status === 'signed' || state.status === 'paper';
    return (
        <Badge variant={done ? 'secondary' : 'outline'}>
            {who} : {signLabel(state)}
        </Badge>
    );
}

function CentreObservations({ candidatureId, month, page }: { candidatureId: string; month: string; page: BookletPage }) {
    const [values, setValues] = useState<Weeks>(page.observations);
    const [saving, setSaving] = useState(false);
    const frozen = page.visa !== null;

    return (
        <div className="flex flex-col gap-2">
            {WEEKS.map((w) => (
                <div key={w.key} className="grid gap-1">
                    <label htmlFor={`obs-${month}-${w.key}`} className="text-muted-foreground text-xs">
                        Observations du formateur - {w.label}
                    </label>
                    <textarea
                        id={`obs-${month}-${w.key}`}
                        rows={2}
                        disabled={frozen}
                        className={textareaClass}
                        value={values[w.key] ?? ''}
                        onChange={(e) => setValues({ ...values, [w.key]: e.target.value })}
                    />
                </div>
            ))}
            {!frozen && (
                <div>
                    <Button
                        size="sm"
                        disabled={saving}
                        onClick={() =>
                            router.put(
                                route('admissions.booklet.months.observations', [candidatureId, month, 'centre']),
                                { observations: values },
                                { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
                            )
                        }
                    >
                        Enregistrer les observations
                    </Button>
                </div>
            )}
        </div>
    );
}

function PageCard({ candidatureId, month, place, page }: { candidatureId: string; month: string; place: Place; page: BookletPage }) {
    const visa = () =>
        page.visa
            ? router.delete(route('admissions.booklet.months.unvisa', [candidatureId, month, place]), { preserveScroll: true })
            : router.post(route('admissions.booklet.months.visa', [candidatureId, month, place]), {}, { preserveScroll: true });

    return (
        <div className="flex flex-col gap-3 rounded-md border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h4 className="font-medium">Travaux effectués {PLACE_LABEL[place].toLowerCase()}</h4>
                <div className="flex flex-wrap items-center gap-2">
                    {page.visa && (
                        <span className="text-muted-foreground text-xs">
                            Visé par {page.visa.by ?? 'un utilisateur'} le {page.visa.at}
                        </span>
                    )}
                    <Button size="sm" variant={page.visa ? 'outline' : 'default'} onClick={visa}>
                        {page.visa ? 'Retirer le visa' : 'Viser'}
                    </Button>
                </div>
            </div>
            <div className="grid gap-2 text-sm">
                {WEEKS.map((w) => (
                    <div key={w.key} className="grid gap-1 md:grid-cols-[7rem_1fr_1fr] md:gap-3">
                        <span className="text-muted-foreground">{w.label}</span>
                        <p className="whitespace-pre-line">{page.entries[w.key] ?? <span className="text-muted-foreground">Apprenti : rien de saisi</span>}</p>
                        {place === 'company' && (
                            <p className="whitespace-pre-line">{page.observations[w.key] ?? <span className="text-muted-foreground">Tuteur : rien de saisi</span>}</p>
                        )}
                    </div>
                ))}
            </div>
            {place === 'centre' && <CentreObservations candidatureId={candidatureId} month={month} page={page} />}
        </div>
    );
}

/** The monthly pages, most recent first: what the apprentice and the tutor typed, the centre observations, visa and signatures. */
export function BookletMonths({ candidatureId, months, children }: { candidatureId: string; months: BookletMonthRow[]; children?: (m: BookletMonthRow) => React.ReactNode }) {
    const paper = (month: string, signer: Signer, who: string) =>
        window.confirm(`Noter que ${who} a signé ce mois sur le livret papier ?`) &&
        router.post(route('admissions.booklet.months.paper', [candidatureId, month, signer]), {}, { preserveScroll: true });

    if (months.length === 0) {
        return <p className="text-muted-foreground text-sm">Le contrat n'a pas encore commencé.</p>;
    }

    return (
        <div className="flex flex-col gap-6">
            {months.map((m) => {
                const company = m.pages.company as BookletPage;
                const apprenticeDone = company.apprentice.status === 'signed' || company.apprentice.status === 'paper';
                const tutorDone = company.tutor?.status === 'signed' || company.tutor?.status === 'paper';
                return (
                    <section key={m.month} className="flex flex-col gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h3 className="text-lg font-semibold">{m.label}</h3>
                            <div className="flex flex-wrap items-center gap-2">
                                <SignBadge who="Apprenti" state={company.apprentice} />
                                {company.tutor && <SignBadge who="Tuteur" state={company.tutor} />}
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {children?.(m)}
                            {!apprenticeDone && (
                                <Button size="sm" variant="outline" onClick={() => paper(m.month, 'apprentice', "l'apprenti")}>
                                    Apprenti : signé sur papier
                                </Button>
                            )}
                            {!tutorDone && (
                                <Button size="sm" variant="outline" onClick={() => paper(m.month, 'tutor', 'le tuteur')}>
                                    Tuteur : signé sur papier
                                </Button>
                            )}
                        </div>
                        {m.places.map((place) => (
                            <PageCard key={place} candidatureId={candidatureId} month={m.month} place={place} page={m.pages[place] as BookletPage} />
                        ))}
                    </section>
                );
            })}
        </div>
    );
}
