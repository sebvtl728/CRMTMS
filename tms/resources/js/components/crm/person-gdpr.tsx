import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDate, formatDateTime, type Option } from '@/lib/crm';
import { router, useForm } from '@inertiajs/react';
import { Download, Eraser, Plus, ShieldOff } from 'lucide-react';
import { useState } from 'react';

export interface ConsentRow {
    id: string;
    channel: { value: string; label: string };
    granted: boolean;
    source: string | null;
    recorded_at: string;
    recorded_by: string | null;
}

export interface GdprData {
    consents: ConsentRow[];
    channels: Option[];
    pending: { listed_at: string; planned_at: string } | null;
    kept_until: string | null;
    kept_reason: string | null;
}

interface Props {
    personId: string;
    name: string;
    legalBasis: { value: string; label: string };
    optOutAt: string | null;
    anonymisedAt: string | null;
    gdpr: GdprData;
    can: { consent: boolean; gdpr: boolean; anonymise: boolean };
}

const normalise = (text: string) => text.trim().replace(/\s+/g, ' ').toLowerCase();

/** « RGPD » section of a person's fiche: legal basis, consents, export, opposition, anonymisation. */
export function PersonGdpr({ personId, name, legalBasis, optOutAt, anonymisedAt, gdpr, can }: Props) {
    const [adding, setAdding] = useState(false);
    const [anonymising, setAnonymising] = useState(false);
    const [opposing, setOpposing] = useState(false);

    const consent = useForm({ channel: gdpr.channels[0]?.value ?? 'email', granted: '1', source: '' });
    const confirm = useForm({ confirmation: '' });

    const addConsent = (e: React.FormEvent) => {
        e.preventDefault();
        consent.transform((d) => ({ ...d, granted: d.granted === '1' }));
        consent.post(route('crm.people.consents.store', personId), {
            preserveScroll: true,
            onSuccess: () => {
                setAdding(false);
                consent.reset();
            },
        });
    };

    const anonymise = (e: React.FormEvent) => {
        e.preventDefault();
        confirm.post(route('crm.people.anonymise', personId), { preserveScroll: true, onSuccess: () => setAnonymising(false) });
    };

    return (
        <section className="rounded-xl border p-4 lg:col-span-2" aria-labelledby="gdpr-title">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 id="gdpr-title" className="text-sm font-semibold">
                    RGPD
                </h2>
                <div className="flex flex-wrap gap-2">
                    {can.gdpr && (
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('crm.people.gdpr-export', personId)} download>
                                <Download className="h-4 w-4" />
                                Exporter ses données
                            </a>
                        </Button>
                    )}
                    {can.consent && !optOutAt && (
                        <Button variant="outline" size="sm" onClick={() => setOpposing(true)}>
                            <ShieldOff className="h-4 w-4" />
                            Opposition à la prospection
                        </Button>
                    )}
                    {can.anonymise && (
                        <Button variant="destructive" size="sm" onClick={() => setAnonymising(true)}>
                            <Eraser className="h-4 w-4" />
                            Anonymiser maintenant
                        </Button>
                    )}
                </div>
            </div>

            <dl className="grid gap-3 sm:grid-cols-3">
                <div>
                    <dt className="text-muted-foreground text-xs">Base légale</dt>
                    <dd className="text-sm">{legalBasis.label}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-xs">Prospection</dt>
                    <dd className="text-sm">{optOutAt ? `Opposée depuis le ${formatDate(optOutAt)}` : 'Pas d’opposition enregistrée'}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-xs">Conservation</dt>
                    <dd className="text-sm">
                        {anonymisedAt
                            ? `Anonymisée le ${formatDate(anonymisedAt)}`
                            : gdpr.pending
                              ? `À anonymiser le ${formatDate(gdpr.pending.planned_at)}`
                              : gdpr.kept_until
                                ? `Conservée jusqu’au ${formatDate(gdpr.kept_until)}${gdpr.kept_reason ? ` (${gdpr.kept_reason})` : ''}`
                                : 'Durée de conservation en cours'}
                    </dd>
                </div>
            </dl>

            <div className="mt-4">
                <div className="mb-2 flex items-center justify-between gap-2">
                    <h3 className="text-muted-foreground text-xs font-medium">Consentements</h3>
                    {can.consent && (
                        <Button variant="ghost" size="sm" onClick={() => setAdding(true)}>
                            <Plus className="h-4 w-4" />
                            Ajouter
                        </Button>
                    )}
                </div>
                {gdpr.consents.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Aucun consentement enregistré.</p>
                ) : (
                    <ul className="grid gap-2">
                        {gdpr.consents.map((c) => (
                            <li key={c.id} className="flex flex-wrap items-center gap-2 text-sm">
                                <Badge variant={c.granted ? 'secondary' : 'outline'}>
                                    {c.channel.label} : {c.granted ? 'accordé' : 'refusé'}
                                </Badge>
                                <span className="text-muted-foreground">
                                    {formatDateTime(c.recorded_at)}
                                    {c.recorded_by ? ` · ${c.recorded_by}` : ''}
                                    {c.source ? ` · ${c.source}` : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent>
                    <form onSubmit={addConsent} className="grid gap-4">
                        <DialogHeader>
                            <DialogTitle>Enregistrer un consentement</DialogTitle>
                            <DialogDescription>L’historique est conservé : un nouveau choix s’ajoute sans effacer les précédents.</DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="consent-channel">Canal</Label>
                            <Select value={consent.data.channel} onValueChange={(v) => consent.setData('channel', v)}>
                                <SelectTrigger id="consent-channel">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {gdpr.channels.map((o) => (
                                        <SelectItem key={o.value} value={o.value}>
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={consent.errors.channel} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="consent-granted">Choix</Label>
                            <Select value={consent.data.granted} onValueChange={(v) => consent.setData('granted', v)}>
                                <SelectTrigger id="consent-granted">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="1">Accordé</SelectItem>
                                    <SelectItem value="0">Refusé ou retiré</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={consent.errors.granted} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="consent-source">Source (facultatif)</Label>
                            <Input
                                id="consent-source"
                                maxLength={255}
                                placeholder="Formulaire salon, appel…"
                                value={consent.data.source}
                                onChange={(e) => consent.setData('source', e.target.value)}
                            />
                            <InputError message={consent.errors.source} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setAdding(false)}>
                                Annuler
                            </Button>
                            <Button type="submit" disabled={consent.processing}>
                                Enregistrer
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={opposing} onOpenChange={setOpposing}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Enregistrer l’opposition à la prospection ?</DialogTitle>
                        <DialogDescription>
                            « {name} » ne doit plus recevoir de sollicitation commerciale. Un badge l’indiquera dans les listes.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setOpposing(false)}>
                            Annuler
                        </Button>
                        <Button
                            onClick={() =>
                                router.post(route('crm.people.opt-out', personId), {}, { preserveScroll: true, onSuccess: () => setOpposing(false) })
                            }
                        >
                            Enregistrer l’opposition
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={anonymising} onOpenChange={setAnonymising}>
                <DialogContent>
                    <form onSubmit={anonymise} className="grid gap-4">
                        <DialogHeader>
                            <DialogTitle>Anonymiser cette personne ?</DialogTitle>
                            <DialogDescription>
                                Action irréversible : nom, coordonnées, notes, pièces jointes et valeurs importées de « {name} » seront effacés
                                définitivement. Seules les données statistiques (statut, DAS, rattachements) restent.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="confirmation">Pour confirmer, tapez « {name} »</Label>
                            <Input
                                id="confirmation"
                                autoComplete="off"
                                value={confirm.data.confirmation}
                                onChange={(e) => confirm.setData('confirmation', e.target.value)}
                            />
                            <InputError message={confirm.errors.confirmation} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setAnonymising(false)}>
                                Annuler
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={confirm.processing || normalise(confirm.data.confirmation) !== normalise(name)}
                            >
                                Anonymiser définitivement
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </section>
    );
}
