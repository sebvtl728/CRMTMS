import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Unavailabilities, type UnavailabilityRow } from '@/components/sessions/unavailabilities';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type Option } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Props {
    trainer: {
        id: string;
        label: string;
        email: string | null;
        status: string;
        status_label: string;
        active: boolean;
        user: { id: string; name: string; email: string; active: boolean } | null;
        fiche_url: string | null;
    };
    unavailabilities: UnavailabilityRow[];
    statusOptions: Option[];
    userOptions: Option[];
    invite_url: string | null;
    status?: string;
}

const NONE = '__none__';

export default function TrainerShow({ trainer, unavailabilities, statusOptions, userOptions, invite_url, status }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Formateurs', href: '/trainers' },
        { title: trainer.label, href: `/trainers/${trainer.id}` },
    ];
    const details = useForm({ status: trainer.status, active: trainer.active });
    const account = useForm<{ user_id: string | null }>({ user_id: trainer.user?.id ?? null });

    const saveDetails: FormEventHandler = (e) => {
        e.preventDefault();
        details.put(route('trainers.update', trainer.id), { preserveScroll: true });
    };
    const saveAccount: FormEventHandler = (e) => {
        e.preventDefault();
        account.put(route('trainers.user', trainer.id), { preserveScroll: true });
    };
    const remove = () => {
        if (window.confirm(`Supprimer le formateur ${trainer.label} ? La personne reste dans le CRM.`)) {
            router.delete(route('trainers.destroy', trainer.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={trainer.label} />

            <div className="flex flex-col gap-8 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <Heading title={trainer.label} description={`Formateur ${trainer.status_label.toLowerCase()}${trainer.email ? ` · ${trainer.email}` : ''}`} />
                    <div className="flex items-center gap-2">
                        {!trainer.active && <Badge variant="outline">Inactif</Badge>}
                        {trainer.fiche_url && (
                            <Button variant="outline" asChild>
                                <Link href={trainer.fiche_url}>Fiche de la personne</Link>
                            </Button>
                        )}
                        <Button variant="outline" onClick={remove}>
                            Supprimer
                        </Button>
                    </div>
                </div>

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <section className="flex max-w-xl flex-col gap-3" aria-labelledby="details_title">
                    <h2 id="details_title" className="text-lg font-semibold">
                        Statut
                    </h2>
                    <form onSubmit={saveDetails} className="flex flex-wrap items-end gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="trainer_status">Statut</Label>
                            <Select value={details.data.status} onValueChange={(v) => details.setData('status', v)}>
                                <SelectTrigger id="trainer_status" className="w-48">
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
                            <InputError message={details.errors.status} />
                        </div>
                        <label className="flex items-center gap-2 pb-2 text-sm">
                            <Checkbox checked={details.data.active} onCheckedChange={(c) => details.setData('active', c === true)} />
                            Actif (proposé au planning)
                        </label>
                        <Button type="submit" disabled={details.processing}>
                            Enregistrer
                        </Button>
                    </form>
                </section>

                <section className="flex max-w-xl flex-col gap-3" aria-labelledby="account_title">
                    <h2 id="account_title" className="text-lg font-semibold">
                        Compte TMS
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        Lié à un compte, le formateur voit son planning et saisit les présences de ses séances (rôle « Formateur »).
                    </p>
                    <form onSubmit={saveAccount} className="flex flex-wrap items-end gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="trainer_user">Compte lié</Label>
                            <Select value={account.data.user_id ?? NONE} onValueChange={(v) => account.setData('user_id', v === NONE ? null : v)}>
                                <SelectTrigger id="trainer_user" className="w-80">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Aucun compte</SelectItem>
                                    {userOptions.map((o) => (
                                        <SelectItem key={o.value} value={o.value}>
                                            {o.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={account.errors.user_id} />
                        </div>
                        <Button type="submit" disabled={account.processing}>
                            Enregistrer
                        </Button>
                    </form>
                    {invite_url && (
                        <div>
                            <Button variant="outline" asChild>
                                <Link href={invite_url}>Inviter à se connecter</Link>
                            </Button>
                            <p className="text-muted-foreground mt-1 text-sm">
                                L'invitation propose le rôle « Formateur » ; à l'activation, le compte est lié à ce formateur s'il est le seul à avoir cet
                                e-mail.
                            </p>
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-3" aria-labelledby="unavailabilities_title">
                    <h2 id="unavailabilities_title" className="text-lg font-semibold">
                        Indisponibilités
                    </h2>
                    <Unavailabilities
                        rows={unavailabilities}
                        storeUrl={route('trainers.unavailabilities.store', trainer.id)}
                        destroyUrl={(id) => route('trainers.unavailabilities.destroy', [trainer.id, id])}
                    />
                </section>
            </div>
        </AppLayout>
    );
}
