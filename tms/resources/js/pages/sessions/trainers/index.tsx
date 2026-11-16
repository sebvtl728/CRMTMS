import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { FichePicker, type Picked } from '@/components/sessions/fiche-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface TrainerRow {
    id: string;
    label: string;
    email: string | null;
    status: string;
    status_label: string;
    active: boolean;
    user: { name: string; email: string } | null;
}

interface Props {
    trainers: TrainerRow[];
    statusOptions: Option[];
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Formateurs', href: '/trainers' }];

type NewTrainer = {
    status: string;
    person_id: string;
    person: { last_name: string; first_name: string; email: string; phone: string } | null;
    confirm_duplicate: boolean;
};

function AddTrainerDialog({ statusOptions, onClose }: { statusOptions: Option[]; onClose: () => void }) {
    const [mode, setMode] = useState<'existing' | 'new'>('existing');
    const [picked, setPicked] = useState<Picked | null>(null);
    const { data, setData, post, processing, errors, transform } = useForm<NewTrainer>({
        status: 'employee',
        person_id: '',
        person: { last_name: '', first_name: '', email: '', phone: '' },
        confirm_duplicate: false,
    });
    const err = errors as Record<string, string | undefined>;
    const person = data.person ?? { last_name: '', first_name: '', email: '', phone: '' };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((d) => (mode === 'existing' ? { status: d.status, person_id: picked?.id ?? '' } : { ...d, person_id: '' }));
        post(route('trainers.store'), { preserveScroll: true });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Ajouter un formateur</DialogTitle>
                        <DialogDescription>Un formateur est une personne du CRM : choisissez-la, ou créez-la si elle n'existe pas encore.</DialogDescription>
                    </DialogHeader>
                    <div className="flex gap-4 text-sm" role="radiogroup" aria-label="Personne">
                        <label className="flex items-center gap-2">
                            <input type="radio" name="trainer_mode" checked={mode === 'existing'} onChange={() => setMode('existing')} />
                            Personne existante
                        </label>
                        <label className="flex items-center gap-2">
                            <input type="radio" name="trainer_mode" checked={mode === 'new'} onChange={() => setMode('new')} />
                            Nouvelle personne
                        </label>
                    </div>
                    {mode === 'existing' ? (
                        <div className="grid gap-2">
                            <Label htmlFor="trainer_person">Personne *</Label>
                            <FichePicker
                                id="trainer_person"
                                kind="person"
                                value={picked}
                                onPick={setPicked}
                                placeholder="Nom, prénom ou e-mail…"
                                endpoint={route('trainers.people')}
                            />
                            <InputError message={err.person_id} />
                        </div>
                    ) : (
                        <div className="grid gap-3">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="trainer_last_name">Nom *</Label>
                                    <Input id="trainer_last_name" value={person.last_name} onChange={(e) => setData('person', { ...person, last_name: e.target.value })} />
                                    <InputError message={err['person.last_name']} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="trainer_first_name">Prénom</Label>
                                    <Input id="trainer_first_name" value={person.first_name} onChange={(e) => setData('person', { ...person, first_name: e.target.value })} />
                                    <InputError message={err['person.first_name']} />
                                </div>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="trainer_email">E-mail</Label>
                                    <Input id="trainer_email" type="email" value={person.email} onChange={(e) => setData('person', { ...person, email: e.target.value })} />
                                    <InputError message={err['person.email']} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="trainer_phone">Téléphone</Label>
                                    <Input id="trainer_phone" value={person.phone} onChange={(e) => setData('person', { ...person, phone: e.target.value })} />
                                    <InputError message={err['person.phone']} />
                                </div>
                            </div>
                            {err['person.last_name']?.startsWith('Une personne semblable') && (
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={data.confirm_duplicate} onCheckedChange={(c) => setData('confirm_duplicate', c === true)} />
                                    Créer quand même cette personne
                                </label>
                            )}
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="trainer_status">Statut *</Label>
                        <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                            <SelectTrigger id="trainer_status" className="w-56">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {statusOptions.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={err.status} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Ajouter
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Trainers({ trainers, statusOptions, status }: Props) {
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Formateurs" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading title="Formateurs" description="Les formateurs salariés et indépendants de l'organisme, leurs indisponibilités et leur compte." />
                    <Button onClick={() => setAdding(true)}>
                        <Plus className="mr-1 h-4 w-4" /> Ajouter un formateur
                    </Button>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                {trainers.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Aucun formateur.</p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Formateur</th>
                                    <th className="px-3 py-2 font-medium">E-mail</th>
                                    <th className="px-3 py-2 font-medium">Statut</th>
                                    <th className="px-3 py-2 font-medium">Compte</th>
                                    <th className="px-3 py-2 font-medium">État</th>
                                </tr>
                            </thead>
                            <tbody>
                                {trainers.map((t) => (
                                    <tr key={t.id} className="border-t">
                                        <td className="px-3 py-2 font-medium">
                                            <Link href={route('trainers.show', t.id)} className="hover:underline">
                                                {t.label}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2">{t.email ?? '—'}</td>
                                        <td className="px-3 py-2">{t.status_label}</td>
                                        <td className="px-3 py-2">{t.user ? t.user.email : <span className="text-muted-foreground">Sans compte</span>}</td>
                                        <td className="px-3 py-2">{t.active ? <Badge variant="secondary">Actif</Badge> : <Badge variant="outline">Inactif</Badge>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {adding && <AddTrainerDialog statusOptions={statusOptions} onClose={() => setAdding(false)} />}
        </AppLayout>
    );
}
