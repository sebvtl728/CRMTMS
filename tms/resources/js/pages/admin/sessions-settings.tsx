import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDay } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface RoomRow {
    id: string;
    name: string;
    capacity: number | null;
    address: string | null;
    active: boolean;
}

interface ClosureRow {
    id: string;
    starts_on: string;
    ends_on: string;
    label: string;
}

interface Props {
    rooms: RoomRow[];
    closures: ClosureRow[];
    status?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Salles et fermetures', href: '/admin/sessions-settings' }];

function RoomDialog({ room, onClose }: { room: RoomRow | null; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: room?.name ?? '',
        capacity: room?.capacity === null || room?.capacity === undefined ? '' : String(room.capacity),
        address: room?.address ?? '',
        active: room?.active ?? true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const visit = { preserveScroll: true, onSuccess: onClose };
        if (room) {
            put(route('admin.sessions-settings.rooms.update', room.id), visit);
        } else {
            post(route('admin.sessions-settings.rooms.store'), visit);
        }
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{room ? 'Modifier la salle' : 'Ajouter une salle'}</DialogTitle>
                        <DialogDescription>Une salle utilisée par des séances se désactive, elle ne se supprime pas.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="room_name">Nom *</Label>
                        <Input id="room_name" value={data.name} maxLength={120} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="room_capacity">Capacité (places)</Label>
                        <Input
                            id="room_capacity"
                            type="number"
                            min={1}
                            step={1}
                            className="w-40"
                            value={data.capacity}
                            onChange={(e) => setData('capacity', e.target.value)}
                        />
                        <InputError message={errors.capacity} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="room_address">Adresse</Label>
                        <Input id="room_address" value={data.address} maxLength={500} onChange={(e) => setData('address', e.target.value)} />
                        <InputError message={errors.address} />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox checked={data.active} onCheckedChange={(c) => setData('active', c === true)} />
                        Salle active (proposée au planning)
                    </label>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Enregistrer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ClosureDialog({ closure, onClose }: { closure: ClosureRow | null; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm({
        starts_on: closure?.starts_on ?? '',
        ends_on: closure?.ends_on ?? '',
        label: closure?.label ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const visit = { preserveScroll: true, onSuccess: onClose };
        if (closure) {
            put(route('admin.sessions-settings.closures.update', closure.id), visit);
        } else {
            post(route('admin.sessions-settings.closures.store'), visit);
        }
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>{closure ? 'Modifier la fermeture' : 'Ajouter une fermeture'}</DialogTitle>
                        <DialogDescription>Aucune séance n'est générée pendant une fermeture (premier et dernier jour inclus).</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="closure_label">Intitulé *</Label>
                        <Input id="closure_label" value={data.label} maxLength={120} onChange={(e) => setData('label', e.target.value)} />
                        <InputError message={errors.label} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="closure_starts">Du *</Label>
                            <Input id="closure_starts" type="date" value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} />
                            <InputError message={errors.starts_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="closure_ends">Au *</Label>
                            <Input id="closure_ends" type="date" value={data.ends_on} onChange={(e) => setData('ends_on', e.target.value)} />
                            <InputError message={errors.ends_on} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Annuler
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Enregistrer
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function SessionsSettings({ rooms, closures, status }: Props) {
    const [room, setRoom] = useState<RoomRow | null | 'new'>(null);
    const [closure, setClosure] = useState<ClosureRow | null | 'new'>(null);

    const removeRoom = (r: RoomRow) => {
        if (window.confirm(`Supprimer la salle « ${r.name} » ?`)) {
            router.delete(route('admin.sessions-settings.rooms.destroy', r.id), { preserveScroll: true });
        }
    };
    const removeClosure = (c: ClosureRow) => {
        if (window.confirm(`Supprimer la fermeture « ${c.label} » ?`)) {
            router.delete(route('admin.sessions-settings.closures.destroy', c.id), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Salles et fermetures" />

            <div className="flex flex-col gap-8 p-4">
                <Heading title="Salles et fermetures" description="Les salles de formation et les périodes de fermeture de l'organisme, utilisées par le planning des séances." />

                {status && <div className="text-sm font-medium text-green-600">{status}</div>}

                <section className="flex flex-col gap-3" aria-labelledby="rooms_title">
                    <div className="flex items-center justify-between gap-2">
                        <h2 id="rooms_title" className="text-lg font-semibold">
                            Salles
                        </h2>
                        <Button size="sm" onClick={() => setRoom('new')}>
                            <Plus className="mr-1 h-4 w-4" /> Ajouter une salle
                        </Button>
                    </div>
                    {rooms.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Aucune salle.</p>
                    ) : (
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Nom</th>
                                        <th className="px-3 py-2 font-medium">Capacité</th>
                                        <th className="px-3 py-2 font-medium">Adresse</th>
                                        <th className="px-3 py-2 font-medium">État</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {rooms.map((r) => (
                                        <tr key={r.id} className="border-t">
                                            <td className="px-3 py-2 font-medium">{r.name}</td>
                                            <td className="px-3 py-2">{r.capacity === null ? '—' : `${r.capacity} places`}</td>
                                            <td className="px-3 py-2">{r.address ?? '—'}</td>
                                            <td className="px-3 py-2">{r.active ? <Badge variant="secondary">Active</Badge> : <Badge variant="outline">Inactive</Badge>}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">
                                                <Button variant="ghost" size="icon" aria-label={`Modifier ${r.name}`} onClick={() => setRoom(r)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button variant="ghost" size="icon" aria-label={`Supprimer ${r.name}`} onClick={() => removeRoom(r)}>
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-3" aria-labelledby="closures_title">
                    <div className="flex items-center justify-between gap-2">
                        <h2 id="closures_title" className="text-lg font-semibold">
                            Périodes de fermeture
                        </h2>
                        <Button size="sm" onClick={() => setClosure('new')}>
                            <Plus className="mr-1 h-4 w-4" /> Ajouter une fermeture
                        </Button>
                    </div>
                    <p className="text-muted-foreground text-sm">Les jours fériés nationaux sont exclus automatiquement : inutile de les saisir.</p>
                    {closures.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Aucune période de fermeture.</p>
                    ) : (
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2 font-medium">Intitulé</th>
                                        <th className="px-3 py-2 font-medium">Du</th>
                                        <th className="px-3 py-2 font-medium">Au</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {closures.map((c) => (
                                        <tr key={c.id} className="border-t">
                                            <td className="px-3 py-2 font-medium">{c.label}</td>
                                            <td className="px-3 py-2">{formatDay(c.starts_on)}</td>
                                            <td className="px-3 py-2">{formatDay(c.ends_on)}</td>
                                            <td className="px-3 py-2 text-right whitespace-nowrap">
                                                <Button variant="ghost" size="icon" aria-label={`Modifier ${c.label}`} onClick={() => setClosure(c)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button variant="ghost" size="icon" aria-label={`Supprimer ${c.label}`} onClick={() => removeClosure(c)}>
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>

            {room !== null && <RoomDialog room={room === 'new' ? null : room} onClose={() => setRoom(null)} />}
            {closure !== null && <ClosureDialog closure={closure === 'new' ? null : closure} onClose={() => setClosure(null)} />}
        </AppLayout>
    );
}
