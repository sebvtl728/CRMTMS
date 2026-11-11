import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface ActivateProps {
    token: string;
    email: string;
    name: string;
}

type ActivateForm = {
    password: string;
    password_confirmation: string;
};

export default function Activate({ token, email, name }: ActivateProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ActivateForm>({
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('activation.store', token), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Activation de votre compte" description={`Bienvenue ${name}, choisissez votre mot de passe pour activer votre compte.`}>
            <Head title="Activation du compte" />

            <form onSubmit={submit}>
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="email">Adresse e-mail</Label>
                        <Input id="email" type="email" name="email" autoComplete="username" value={email} className="mt-1 block w-full" readOnly />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">Mot de passe</Label>
                        <Input
                            id="password"
                            type="password"
                            name="password"
                            autoComplete="new-password"
                            value={data.password}
                            className="mt-1 block w-full"
                            autoFocus
                            required
                            minLength={12}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder="Au moins 12 caractères"
                        />
                        <p className="text-muted-foreground text-xs">
                            12 caractères minimum. Les mots de passe connus pour avoir fuité sont refusés.
                        </p>
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">Confirmer le mot de passe</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            className="mt-1 block w-full"
                            required
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            placeholder="Confirmer le mot de passe"
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>

                    <Button type="submit" className="mt-4 w-full" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Activer mon compte
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
