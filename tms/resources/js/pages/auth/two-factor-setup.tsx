import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface TwoFactorSetupProps {
    enabled: boolean;
    required: boolean;
    qrCodeSvg: string | null;
    secret: string | null;
    recoveryCodes: string[] | null;
    recoveryCodesLeft: number | null;
    status?: string | null;
}

export default function TwoFactorSetup({ enabled, required, qrCodeSvg, secret, recoveryCodes, recoveryCodesLeft, status }: TwoFactorSetupProps) {
    const confirmForm = useForm({ code: '' });
    const actionForm = useForm({});

    const confirm: FormEventHandler = (e) => {
        e.preventDefault();
        confirmForm.post(route('two-factor.confirm'), { onFinish: () => confirmForm.reset('code') });
    };

    const regenerate: FormEventHandler = (e) => {
        e.preventDefault();
        actionForm.post(route('two-factor.recovery-codes'));
    };

    const disable: FormEventHandler = (e) => {
        e.preventDefault();
        actionForm.delete(route('two-factor.disable'));
    };

    if (!enabled) {
        return (
            <AuthLayout
                title="Activer la double authentification"
                description={
                    required
                        ? 'La double authentification est obligatoire pour votre compte. Activez-la pour continuer.'
                        : 'Protégez votre compte avec un code à usage unique en plus de votre mot de passe.'
                }
            >
                <Head title="Double authentification" />

                <ol className="text-muted-foreground list-decimal space-y-2 pl-5 text-sm">
                    <li>Installez une application d'authentification (Microsoft Authenticator, Google Authenticator, FreeOTP…).</li>
                    <li>Scannez le QR code ci-dessous, ou saisissez la clé manuellement.</li>
                    <li>Saisissez le code à 6 chiffres affiché par l'application.</li>
                </ol>

                {qrCodeSvg && (
                    <div
                        className="mx-auto w-48 rounded-md bg-white p-2"
                        role="img"
                        aria-label="QR code de configuration"
                        // SVG generated server-side by bacon-qr-code (no user input).
                        dangerouslySetInnerHTML={{ __html: qrCodeSvg }}
                    />
                )}

                {secret && (
                    <p className="text-center text-sm">
                        Clé : <code className="font-mono tracking-wider select-all">{secret}</code>
                    </p>
                )}

                <form onSubmit={confirm} className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="code">Code de vérification</Label>
                        <Input
                            id="code"
                            name="code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            autoFocus
                            maxLength={6}
                            placeholder="123456"
                            value={confirmForm.data.code}
                            onChange={(e) => confirmForm.setData('code', e.target.value)}
                        />
                        <InputError message={confirmForm.errors.code} />
                    </div>

                    <Button className="w-full" disabled={confirmForm.processing}>
                        {confirmForm.processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Activer
                    </Button>
                </form>

                <div className="text-muted-foreground text-center text-sm">
                    <TextLink href={route('logout')} method="post" as="button">
                        Se déconnecter
                    </TextLink>
                </div>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout title="Double authentification" description="La double authentification protège votre compte.">
            <Head title="Double authentification" />

            {status && <div className="text-center text-sm font-medium text-green-600">{status}</div>}

            {recoveryCodes && recoveryCodes.length > 0 ? (
                <div className="grid gap-3">
                    <p className="text-sm">
                        Conservez ces codes de récupération en lieu sûr. Chacun permet de vous connecter une seule fois si vous n'avez plus accès à
                        votre application. Ils ne seront plus affichés.
                    </p>
                    <ul className="bg-muted grid grid-cols-2 gap-1 rounded-md p-3 font-mono text-sm">
                        {recoveryCodes.map((code) => (
                            <li key={code}>{code}</li>
                        ))}
                    </ul>
                </div>
            ) : (
                <p className="text-muted-foreground text-center text-sm">Codes de récupération restants : {recoveryCodesLeft ?? 0}</p>
            )}

            <Button asChild className="w-full">
                <Link href={route('dashboard')}>Continuer</Link>
            </Button>

            <form onSubmit={regenerate}>
                <Button variant="secondary" className="w-full" disabled={actionForm.processing}>
                    Générer de nouveaux codes de récupération
                </Button>
            </form>

            {!required && (
                <form onSubmit={disable}>
                    <Button variant="destructive" className="w-full" disabled={actionForm.processing}>
                        Désactiver la double authentification
                    </Button>
                </form>
            )}
        </AuthLayout>
    );
}
