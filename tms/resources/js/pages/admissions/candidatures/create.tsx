import { OptionSelect } from '@/components/admissions/fields';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { FichePicker, type Picked } from '@/components/sessions/fiche-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AdmissionOptions, type CustomField } from '@/types/admissions';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface CandidateCard extends Picked {
    age: number | null;
    situation: string | null;
}

interface CreateProps {
    person: CandidateCard | null;
    options: AdmissionOptions;
    custom_fields: CustomField[];
    das_recipients: Record<string, string[]>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Candidats', href: '/admissions/candidatures' },
    { title: 'Nouvelle candidature', href: '#' },
];

interface NewPerson {
    last_name: string;
    first_name: string;
    email: string;
    phone: string;
    birth_date: string;
}

function csrf(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
}

/**
 * Opens a candidature: a person of the CRM (an interlocuteur becomes a
 * candidate) or a new one, then the training aimed at.
 */
export default function CandidatureCreate({ person, options, das_recipients }: CreateProps) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const [picked, setPicked] = useState<Picked | null>(person);
    const [creating, setCreating] = useState(false);
    const [draft, setDraft] = useState<NewPerson>({ last_name: '', first_name: '', email: '', phone: '', birth_date: '' });
    const [draftErrors, setDraftErrors] = useState<Record<string, string>>({});
    const [duplicates, setDuplicates] = useState<{ id: string; last_name: string; first_name: string | null }[]>([]);
    const [trainingId, setTrainingId] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    // Age and situation are read by the server: reload the person prop when another one is picked.
    const pick = (p: Picked | null) => {
        setPicked(p);
        if (p !== null && p.id !== person?.id) {
            router.get(
                '/admissions/candidatures/create',
                { person: p.id },
                { only: ['person'], preserveState: true, preserveScroll: true, replace: true },
            );
        }
    };
    const card = person !== null && picked !== null && person.id === picked.id ? person : null;
    const training = options.training_options.find((t) => t.value === trainingId) ?? null;
    const das = training?.das_id ? (options.das.find((d) => d.value === training.das_id) ?? null) : null;
    const recipients = training?.das_id ? (das_recipients[training.das_id] ?? []) : [];

    const createPerson = async (confirm: boolean) => {
        setDraftErrors({});
        const response = await fetch('/admissions/candidatures/people', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ ...draft, confirm_duplicate: confirm }),
        });
        const json = await response.json().catch(() => ({}));
        if (response.status === 409) {
            setDuplicates(json.duplicates ?? []);
            return;
        }
        if (response.status === 422) {
            const errs: Record<string, string> = {};
            Object.entries((json.errors ?? {}) as Record<string, string[]>).forEach(([k, v]) => (errs[k] = v[0]));
            setDraftErrors(errs);
            return;
        }
        if (response.ok) {
            pick({ id: json.id, label: [draft.first_name, draft.last_name].filter(Boolean).join(' ') });
            setCreating(false);
            setDuplicates([]);
        }
    };

    const submit = () => {
        if (!picked) return;
        setProcessing(true);
        router.post('/admissions/candidatures', { person_id: picked.id, training_id: trainingId }, { onFinish: () => setProcessing(false) });
    };

    const field = (key: keyof NewPerson, label: string, type = 'text') => (
        <div className="grid gap-1">
            <Label htmlFor={`new-${key}`}>{label}</Label>
            <Input id={`new-${key}`} type={type} value={draft[key]} onChange={(e) => setDraft({ ...draft, [key]: e.target.value })} />
            <InputError message={draftErrors[key]} />
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Nouvelle candidature" />
            <div className="flex max-w-2xl flex-col gap-6 p-4">
                <Heading title="Nouvelle candidature" description="Un interlocuteur du CRM devient candidat, ou une nouvelle personne." />

                <section className="grid gap-3 rounded-xl border p-4">
                    <h2 className="text-sm font-semibold">Candidat</h2>
                    {!creating && (
                        <FichePicker
                            id="person"
                            kind="person"
                            value={picked}
                            onPick={pick}
                            placeholder="Rechercher une personne du CRM (nom, e-mail)"
                            searchUrl="/admissions/candidatures/lookup/people"
                        />
                    )}
                    <InputError message={errors.person_id} />
                    {card && (
                        <dl className="grid grid-cols-2 gap-2 text-sm" aria-label="Profil du candidat">
                            <div>
                                <dt className="text-muted-foreground">Âge</dt>
                                <dd>{card.age === null ? 'Date de naissance non renseignée' : `${card.age} ans`}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">Statut</dt>
                                <dd>{card.situation ?? 'Non renseigné'}</dd>
                            </div>
                        </dl>
                    )}
                    {!picked && !creating && (
                        <Button type="button" variant="outline" size="sm" className="justify-self-start" onClick={() => setCreating(true)}>
                            Créer la personne
                        </Button>
                    )}
                    {creating && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            {field('last_name', 'Nom *')}
                            {field('first_name', 'Prénom')}
                            {field('email', 'E-mail', 'email')}
                            {field('phone', 'Téléphone')}
                            {field('birth_date', 'Date de naissance', 'date')}
                            {duplicates.length > 0 && (
                                <div role="alert" className="grid gap-2 rounded-md border border-amber-300 p-3 text-sm sm:col-span-2">
                                    <p>Des personnes similaires existent déjà :</p>
                                    <ul className="grid gap-1">
                                        {duplicates.map((d) => (
                                            <li key={d.id}>
                                                <button
                                                    type="button"
                                                    className="underline"
                                                    onClick={() => {
                                                        pick({ id: d.id, label: [d.first_name, d.last_name].filter(Boolean).join(' ') });
                                                        setCreating(false);
                                                        setDuplicates([]);
                                                    }}
                                                >
                                                    {[d.first_name, d.last_name].filter(Boolean).join(' ')}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="justify-self-start"
                                        onClick={() => createPerson(true)}
                                    >
                                        Créer quand même
                                    </Button>
                                </div>
                            )}
                            <div className="flex gap-2 sm:col-span-2">
                                <Button type="button" onClick={() => createPerson(false)}>
                                    Créer
                                </Button>
                                <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                                    Annuler
                                </Button>
                            </div>
                        </div>
                    )}
                </section>

                <section className="grid gap-3 rounded-xl border p-4">
                    <h2 className="text-sm font-semibold">Orientation</h2>
                    <OptionSelect
                        id="training_id"
                        label="Formation visée"
                        value={trainingId}
                        options={options.training_options}
                        onChange={setTrainingId}
                        error={errors.training_id ?? errors.das_id}
                    />
                    <div className="grid gap-1 text-sm">
                        <span className="text-muted-foreground">Destinataire</span>
                        <span>
                            {training === null
                                ? 'Choisissez la formation : la candidature ira au responsable de son DAS.'
                                : !training.das_id
                                  ? 'Formation sans DAS : aucun responsable de DAS.'
                                  : recipients.length === 0
                                    ? `Aucun responsable pour le DAS ${das?.label ?? ''}.`
                                    : `${recipients.join(', ')} (responsable${recipients.length > 1 ? 's' : ''} du DAS ${das?.label ?? ''})`}
                        </span>
                    </div>
                </section>

                <div>
                    <Button type="button" onClick={submit} disabled={!picked || processing}>
                        Ouvrir la candidature
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
