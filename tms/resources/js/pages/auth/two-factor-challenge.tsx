import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function TwoFactorChallenge() {
    const [useRecovery, setUseRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ code: '', recovery_code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('two-factor.challenge.store'), { onFinish: () => reset() });
    };

    const toggle = () => {
        setUseRecovery(!useRecovery);
        reset();
        clearErrors();
    };

    return (
        <AuthLayout
            title="Double authentification"
            description={
                useRecovery
                    ? 'Saisissez l’un de vos codes de récupération.'
                    : 'Saisissez le code à 6 chiffres affiché par votre application d’authentification.'
            }
        >
            <Head title="Double authentification" />

            <form onSubmit={submit} className="grid gap-4">
                {useRecovery ? (
                    <div className="grid gap-2">
                        <Label htmlFor="recovery_code">Code de récupération</Label>
                        <Input
                            id="recovery_code"
                            name="recovery_code"
                            autoComplete="off"
                            autoFocus
                            value={data.recovery_code}
                            onChange={(e) => setData('recovery_code', e.target.value)}
                        />
                        <InputError message={errors.recovery_code} />
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="code">Code de vérification</Label>
                        <Input
                            id="code"
                            name="code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            autoFocus
                            maxLength={6}
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                        />
                        <InputError message={errors.code} />
                    </div>
                )}

                <Button className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Se connecter
                </Button>
            </form>

            <div className="text-muted-foreground text-center text-sm">
                <button type="button" className="underline underline-offset-4" onClick={toggle}>
                    {useRecovery ? 'Utiliser un code de l’application' : 'Utiliser un code de récupération'}
                </button>
            </div>
        </AuthLayout>
    );
}
