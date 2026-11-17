import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatDateTime } from '@/lib/crm';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Mon agenda', href: '/settings/calendar' }];

interface OutlookProps {
    available: boolean;
    status: 'none' | 'active' | 'reconnect';
    connected_at: string | null;
    message: string | null;
}

interface CalendarProps {
    calendar: { created_at: string | null; last_used_at: string | null } | null;
    url: string | null;
    status?: string | null;
    /** The CRM actions (meetings, calls) are published; Outlook is offered with them only. */
    crm: boolean;
    /** Séances are published too (linked trainer, session responsible). */
    sources: boolean;
    outlook: OutlookProps | null;
    outlook_error?: string | null;
}

export default function Calendar({ calendar, url, status, crm, sources, outlook, outlook_error }: CalendarProps) {
    const what = [crm ? 'vos rendez-vous et appels planifiés dans le CRM' : null, sources ? 'vos séances de formation' : null]
        .filter(Boolean)
        .join(' et ');

    const [copied, setCopied] = useState(false);
    const [processing, setProcessing] = useState(false);

    const generate = () => {
        if (
            calendar !== null &&
            !window.confirm('Le lien actuel cessera de fonctionner dans les agendas où il est abonné. Créer un nouveau lien ?')
        ) {
            return;
        }
        router.post(route('calendar.store'), {}, { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) });
    };

    const revoke = () => {
        if (!window.confirm('Révoquer le lien ? Vos agendas ne recevront plus vos rendez-vous.')) {
            return;
        }
        router.delete(route('calendar.destroy'), { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) });
    };

    const connectOutlook = () => {
        router.post(route('calendar.outlook.connect'), {}, { onStart: () => setProcessing(true), onFinish: () => setProcessing(false) });
    };

    const disconnectOutlook = () => {
        if (!window.confirm("Déconnecter Outlook ? Vos rendez-vous n'y seront plus envoyés ; les évènements déjà créés restent dans votre agenda.")) {
            return;
        }
        router.delete(route('calendar.outlook.disconnect'), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    };

    const copy = async () => {
        if (url === null) {
            return;
        }
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Mon agenda" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Mon agenda" description={`Abonnez votre agenda (Outlook, Google, Apple) à ${what}.`} />

                    {status && <p className="text-sm font-medium text-green-700 dark:text-green-400">{status}</p>}

                    <div className="text-muted-foreground space-y-2 text-sm">
                        {crm && (
                            <p>
                                Le lien est personnel : toute personne qui le connaît voit vos rendez-vous et appels des 30 derniers jours et des 180
                                prochains jours (type, fiche, lieu, opportunité, résultat). Les comptes rendus n'y figurent jamais.
                            </p>
                        )}
                        {sources && (
                            <p>
                                {crm ? 'Il contient aussi' : 'Le lien est personnel : toute personne qui le connaît voit'} vos séances (celles que
                                vous animez et celles des sessions dont vous êtes responsable) sur la même période : session, module, horaires et
                                salle. Les noms des stagiaires n'y figurent jamais.
                            </p>
                        )}
                        <p>Votre agenda se met à jour automatiquement, en général en quelques heures selon le logiciel.</p>
                    </div>

                    {url !== null && (
                        <div className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950">
                            <Label htmlFor="calendar_url">Votre lien d'abonnement</Label>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Input
                                    id="calendar_url"
                                    readOnly
                                    value={url}
                                    onFocus={(e) => e.currentTarget.select()}
                                    className="font-mono text-xs"
                                />
                                <Button type="button" variant="secondary" onClick={copy}>
                                    {copied ? 'Copié' : 'Copier'}
                                </Button>
                            </div>
                            <p className="text-sm">Copiez-le maintenant : il ne sera plus affiché. Si vous le perdez, créez-en un nouveau.</p>
                        </div>
                    )}

                    {calendar !== null ? (
                        <div className="space-y-1 text-sm">
                            <p>Lien actif, créé le {formatDateTime(calendar.created_at)}.</p>
                            <p className="text-muted-foreground">
                                {calendar.last_used_at !== null
                                    ? `Dernière consultation par un agenda : ${formatDateTime(calendar.last_used_at)}.`
                                    : "Aucun agenda ne l'a encore consulté."}
                            </p>
                        </div>
                    ) : (
                        <p className="text-sm">Aucun lien actif.</p>
                    )}

                    <div className="flex flex-wrap gap-2">
                        <Button type="button" onClick={generate} disabled={processing}>
                            {calendar !== null ? 'Créer un nouveau lien' : "Créer mon lien d'abonnement"}
                        </Button>
                        {calendar !== null && (
                            <Button type="button" variant="destructive" onClick={revoke} disabled={processing}>
                                Révoquer le lien
                            </Button>
                        )}
                    </div>

                    <div className="space-y-4 text-sm">
                        <HeadingSmall title="Comment s'abonner" />
                        <div className="space-y-1">
                            <p className="font-medium">Outlook</p>
                            <p className="text-muted-foreground">
                                Outlook sur le web ou nouvel Outlook : Calendrier › Ajouter un calendrier › S'abonner à partir du web, collez le lien,
                                donnez un nom puis Importer. Outlook classique : Accueil › Ajouter un calendrier › À partir d'Internet.
                            </p>
                        </div>
                        <div className="space-y-1">
                            <p className="font-medium">Google Agenda</p>
                            <p className="text-muted-foreground">
                                Sur un ordinateur : à gauche, à côté de « Autres agendas », cliquez sur + › À partir de l'URL, collez le lien puis
                                Ajouter un agenda. L'agenda apparaît ensuite aussi sur le téléphone.
                            </p>
                        </div>
                        <div className="space-y-1">
                            <p className="font-medium">Apple Calendrier</p>
                            <p className="text-muted-foreground">
                                Sur Mac : Fichier › Nouvel abonnement à un calendrier, collez le lien puis S'abonner. Sur iPhone : Réglages ›
                                Calendrier › Comptes › Ajouter un compte › Autre › Ajouter un calendrier avec abonnement.
                            </p>
                        </div>
                    </div>

                    {outlook !== null && (
                        <div className="space-y-3 text-sm">
                            <HeadingSmall
                                title="Connecter Outlook"
                                description="Avec un compte Microsoft 365, vos rendez-vous et appels sont créés directement dans votre agenda Outlook, mis à jour et supprimés avec eux, en quelques instants."
                            />
                            {outlook_error && <p className="font-medium text-red-700 dark:text-red-400">{outlook_error}</p>}
                            {outlook.message !== null && (
                                <p className="rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950">
                                    {outlook.message}
                                </p>
                            )}
                            {outlook.status === 'active' && (
                                <p>
                                    Outlook connecté{outlook.connected_at !== null ? ` le ${formatDateTime(outlook.connected_at)}` : ''}. Les comptes
                                    rendus n'y sont jamais envoyés.
                                </p>
                            )}
                            {!outlook.available && outlook.status === 'none' && (
                                <p className="text-muted-foreground">
                                    L'envoi vers Outlook n'est pas activé pour votre organisme : contactez votre administrateur.
                                </p>
                            )}
                            <div className="flex flex-wrap gap-2">
                                {outlook.available && outlook.status !== 'active' && (
                                    <Button type="button" onClick={connectOutlook} disabled={processing}>
                                        {outlook.status === 'reconnect' ? 'Reconnecter Outlook' : 'Connecter Outlook'}
                                    </Button>
                                )}
                                {outlook.status !== 'none' && (
                                    <Button type="button" variant="outline" onClick={disconnectOutlook} disabled={processing}>
                                        Déconnecter Outlook
                                    </Button>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
