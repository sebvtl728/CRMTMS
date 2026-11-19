import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface TrainerRow {
    id: string;
    label: string;
    email: string | null;
    status: string;
    status_label: string;
    active: boolean;
    das: string[];
    user: { name: string; email: string } | null;
}

interface Props {
    trainers: TrainerRow[];
    das: string | null;
    dasOptions: Option[];
    statusOptions: Option[];
    userOptions: UserOption[];
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Formateurs', href: '/trainers' }];

const ALL = '__all__';

interface UserOption extends Option {
    /** Holds the Formateur role (séances on « Ses séances »). */
    trainer_role: boolean;
}

type NewTrainer = {
    status: string;
    user_id: string;
    person: { last_name: string; first_name: string; email: string };
};

const NONE = '__none__';

function AddTrainerDialog({ statusOptions, userOptions, onClose }: { statusOptions: Option[]; userOptions: UserOption[]; onClose: () => void }) {
    const [mode, setMode] = useState<'user' | 'person'>('user');
    const { data, setData, post, processing, errors, transform } = useForm<NewTrainer>({
        status: 'employee',
        user_id: '',
        person: { last_name: '', first_name: '', email: '' },
    });
    const err = errors as Record<string, string | undefined>;
    const trainers = userOptions.filter((u) => u.trainer_role);
    const others = userOptions.filter((u) => !u.trainer_role);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((d) => (mode === 'user' ? { status: d.status, user_id: d.user_id } : { status: d.status, person: d.person }));
        post(route('trainers.store'), { preserveScroll: true });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Ajouter un formateur</DialogTitle>
                        <DialogDescription>
                            Choisissez un utilisateur du TMS, ou saisissez un formateur qui n'a pas encore de compte : une invitation avec son e-mail
                            le liera ensuite automatiquement.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex flex-wrap gap-4 text-sm" role="radiogroup" aria-label="Formateur">
                        <label className="flex items-center gap-2">
                            <input type="radio" name="trainer_mode" checked={mode === 'user'} onChange={() => setMode('user')} />
                            Utilisateur du TMS
                        </label>
                        <label className="flex items-center gap-2">
                            <input type="radio" name="trainer_mode" checked={mode === 'person'} onChange={() => setMode('person')} />
                            Formateur sans compte
                        </label>
                    </div>
                    {mode === 'user' ? (
                        <div className="grid gap-2">
                            <Label htmlFor="trainer_user">Utilisateur *</Label>
                            <Select value={data.user_id || NONE} onValueChange={(v) => setData('user_id', v === NONE ? '' : v)}>
                                <SelectTrigger id="trainer_user">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Choisir un utilisateur…</SelectItem>
                                    {trainers.length > 0 && (
                                        <SelectGroup>
                                            <SelectLabel>Rôle Formateur</SelectLabel>
                                            {trainers.map((o) => (
                                                <SelectItem key={o.value} value={o.value}>
                                                    {o.label}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    )}
                                    {others.length > 0 && (
                                        <SelectGroup>
                                            <SelectLabel>Autres utilisateurs</SelectLabel>
                                            {others.map((o) => (
                                                <SelectItem key={o.value} value={o.value}>
                                                    {o.label}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    )}
                                </SelectContent>
                            </Select>
                            {userOptions.length === 0 && <p className="text-muted-foreground text-sm">Tous les utilisateurs sont déjà formateurs.</p>}
                            <InputError message={err.user_id} />
                        </div>
                    ) : (
                        <div className="grid gap-3">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="trainer_last_name">Nom *</Label>
                                    <Input
                                        id="trainer_last_name"
                                        maxLength={100}
                                        value={data.person.last_name}
                                        onChange={(e) => setData('person', { ...data.person, last_name: e.target.value })}
                                    />
                                    <InputError message={err['person.last_name']} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="trainer_first_name">Prénom</Label>
                                    <Input
                                        id="trainer_first_name"
                                        maxLength={100}
                                        value={data.person.first_name}
                                        onChange={(e) => setData('person', { ...data.person, first_name: e.target.value })}
                                    />
                                    <InputError message={err['person.first_name']} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="trainer_email">E-mail</Label>
                                <Input
                                    id="trainer_email"
                                    type="email"
                                    value={data.person.email}
                                    onChange={(e) => setData('person', { ...data.person, email: e.target.value })}
                                />
                                <p className="text-muted-foreground text-sm">Pour lui envoyer son planning et l'inviter plus tard à se connecter.</p>
                                <InputError message={err['person.email']} />
                            </div>
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

export default function Trainers({ trainers, das, dasOptions, statusOptions, userOptions, status }: Props) {
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Formateurs" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading
                        title="Formateurs"
                        description="Les formateurs salariés et indépendants de l'organisme, leurs indisponibilités et leur compte."
                    />
                    <Button onClick={() => setAdding(true)}>
                        <Plus className="mr-1 h-4 w-4" /> Ajouter un formateur
                    </Button>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <div className="grid max-w-xs gap-2">
                    <Label htmlFor="trainers_das">DAS</Label>
                    <Select
                        value={das ?? ALL}
                        onValueChange={(v) =>
                            router.get(route('trainers.index'), v === ALL ? {} : { das: v }, { preserveState: true, replace: true })
                        }
                    >
                        <SelectTrigger id="trainers_das">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Tous les DAS</SelectItem>
                            {dasOptions.map((o) => (
                                <SelectItem key={o.value} value={o.value}>
                                    {o.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

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
                                    <th className="px-3 py-2 font-medium">DAS</th>
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
                                        <td className="px-3 py-2">{t.email ?? '-'}</td>
                                        <td className="px-3 py-2">{t.status_label}</td>
                                        <td className="px-3 py-2">
                                            {t.das.length > 0 ? t.das.join(', ') : <span className="text-muted-foreground">Tous</span>}
                                        </td>
                                        <td className="px-3 py-2">
                                            {t.user ? t.user.email : <span className="text-muted-foreground">Sans compte</span>}
                                        </td>
                                        <td className="px-3 py-2">
                                            {t.active ? <Badge variant="secondary">Actif</Badge> : <Badge variant="outline">Inactif</Badge>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {adding && <AddTrainerDialog statusOptions={statusOptions} userOptions={userOptions} onClose={() => setAdding(false)} />}
        </AppLayout>
    );
}
