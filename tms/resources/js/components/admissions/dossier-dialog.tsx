import { OptionSelect, TextField } from '@/components/admissions/fields';
import InputError from '@/components/input-error';
import { FichePicker, type Picked } from '@/components/sessions/fiche-picker';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { type Option } from '@/lib/crm';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Defaults {
    account: { type: 'organisation' | 'person'; id: string } | null;
    label: string;
    quantity: string;
    unit: string;
    unit_price: string;
    session_id: string | null;
    funding: { funder_organisation_id: string; funding_type_id: string | null; amount: string } | null;
    sessions: { id: string; label: string }[];
}

/**
 * « Créer le dossier » of an admitted candidate, prefilled by the server
 * (contractant, price, session, OPCO funding): the candidate becomes an
 * apprenant, an alternant with a contract.
 */
export function DossierDialog({
    candidatureId,
    personLabel,
    employerLabel,
    fundingTypes,
    open,
    onOpenChange,
}: {
    candidatureId: string;
    personLabel: string;
    employerLabel: string | null;
    fundingTypes: Option[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [data, setData] = useState<Defaults | null>(null);
    const [organisation, setOrganisation] = useState<Picked | null>(null);
    const [withFunding, setWithFunding] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (!open) return;
        setErrors({});
        fetch(`/admissions/candidatures/${candidatureId}/dossier/defaults`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : null))
            .then((json: Defaults | null) => {
                setData(json);
                setOrganisation(
                    json?.account?.type === 'organisation' ? { id: json.account.id, label: employerLabel ?? 'Employeur du contrat' } : null,
                );
                setWithFunding(json?.funding !== null && json?.funding !== undefined);
            })
            .catch(() => setData(null));
    }, [open, candidatureId, employerLabel]);

    if (!data) {
        return (
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Créer le dossier</DialogTitle>
                        <DialogDescription>Chargement…</DialogDescription>
                    </DialogHeader>
                </DialogContent>
            </Dialog>
        );
    }

    const isPerson = data.account?.type === 'person';
    /** Errors shown next to a field; any other one is listed above the buttons, so a click never looks ignored. */
    const SHOWN = [
        'account',
        'account.id',
        'account_organisation_id',
        'account_person_id',
        'label',
        'unit_price',
        'lines.0.unit_price',
        'session_id',
        'person_id',
        'funding.funding_type_id',
        'funding_type_id',
        'funding.amount',
        'amount',
    ];
    const others = Object.entries(errors)
        .filter(([key]) => !SHOWN.includes(key))
        .map(([, message]) => message);
    const set = (changes: Partial<Defaults>) => setData({ ...data, ...changes });

    const submit = () => {
        setProcessing(true);
        setErrors({});
        const account = isPerson ? data.account : organisation ? { type: 'organisation', id: organisation.id } : null;
        router.post(
            `/admissions/candidatures/${candidatureId}/dossier`,
            {
                account,
                label: data.label,
                quantity: data.quantity,
                unit: data.unit,
                unit_price: data.unit_price,
                session_id: data.session_id,
                funding: withFunding && data.funding ? data.funding : null,
            },
            {
                onError: (errs) => setErrors(errs as Record<string, string>),
                onSuccess: (page) => {
                    // A refusal answered as a message (409) comes back as the flash « error ».
                    const flash = (page.props as { flash?: { error?: string | null } }).flash;
                    if (flash?.error) setErrors({ dossier: flash.error });
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Créer le dossier de formation</DialogTitle>
                    <DialogDescription>Le candidat admis devient apprenant ; avec un contrat, apprenant alternant.</DialogDescription>
                </DialogHeader>

                <div className="grid gap-3">
                    <div className="grid gap-1">
                        <Label>Contractant</Label>
                        {isPerson ? (
                            <p className="rounded-md border px-3 py-2 text-sm">{personLabel} (particulier)</p>
                        ) : (
                            <FichePicker
                                id="dossier-account"
                                kind="organisation"
                                value={organisation}
                                onPick={setOrganisation}
                                placeholder="Rechercher l'entreprise contractante"
                                searchUrl="/admissions/candidatures/lookup/organisations"
                            />
                        )}
                        <InputError message={errors.account ?? errors['account.id'] ?? errors.account_organisation_id ?? errors.account_person_id} />
                    </div>
                    <TextField
                        id="dossier-label"
                        label="Libellé de la ligne"
                        value={data.label}
                        onChange={(v) => set({ label: v })}
                        error={errors.label}
                    />
                    <TextField
                        id="dossier-price"
                        label="Prix HT par stagiaire (€)"
                        value={data.unit_price}
                        onChange={(v) => set({ unit_price: v })}
                        error={errors.unit_price ?? errors['lines.0.unit_price']}
                    />
                    <OptionSelect
                        id="dossier-session"
                        label="Session (inscription)"
                        value={data.session_id}
                        options={data.sessions.map((s) => ({ value: s.id, label: s.label }))}
                        onChange={(v) => set({ session_id: v })}
                        error={errors.session_id ?? errors.person_id}
                    />
                    {data.funding && (
                        <div className="grid gap-3 rounded-md border p-3">
                            <Label className="flex items-center gap-2 font-normal">
                                <input type="checkbox" checked={withFunding} onChange={(e) => setWithFunding(e.target.checked)} />
                                Financement par l'OPCO du contrat
                            </Label>
                            {withFunding && (
                                <>
                                    <OptionSelect
                                        id="funding-type"
                                        label="Type de financement"
                                        value={data.funding.funding_type_id}
                                        options={fundingTypes}
                                        onChange={(v) => set({ funding: { ...data.funding!, funding_type_id: v } })}
                                        error={errors['funding.funding_type_id'] ?? errors.funding_type_id}
                                    />
                                    <TextField
                                        id="funding-amount"
                                        label="Montant pris en charge (€)"
                                        value={data.funding.amount}
                                        onChange={(v) => set({ funding: { ...data.funding!, amount: v } })}
                                        error={errors['funding.amount'] ?? errors.amount}
                                    />
                                </>
                            )}
                        </div>
                    )}
                </div>

                {others.length > 0 && (
                    <div role="alert" className="grid gap-1">
                        {others.map((message) => (
                            <InputError key={message} message={message} />
                        ))}
                    </div>
                )}
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                        Annuler
                    </Button>
                    <Button type="button" onClick={submit} disabled={processing}>
                        Créer le dossier
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
