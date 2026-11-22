import InputError from '@/components/input-error';
import SignaturePad from '@/components/signature-pad';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Eye, LoaderCircle, Mail, PenLine } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Props {
    state: 'open' | 'signed' | 'invalid';
    organisme?: string;
    title?: string;
    signer?: string;
    email?: string;
    deadline?: string;
    code_sent?: boolean;
    signed_at?: string | null;
    status?: string;
}

/** The signer's page (no account): read the document, receive a code by e-mail, sign. */
export default function Signature({ state, organisme, title, signer, email, deadline, code_sent, signed_at, status }: Props) {
    const token = window.location.pathname.split('/').filter(Boolean).pop() ?? '';
    const [sending, setSending] = useState(false);
    const errs = usePage().props.errors as Record<string, string | undefined>;
    const { data, setData, post, processing, errors } = useForm<{ accept: boolean; drawing: string; code: string }>({
        accept: false,
        drawing: '',
        code: '',
    });

    if (state === 'invalid') {
        return (
            <AuthLayout
                title="Lien invalide ou expiré"
                description="Ce lien de signature ne fonctionne plus. Demandez un nouvel envoi à l'organisme de formation."
            >
                <Head title="Signature" />
            </AuthLayout>
        );
    }

    const askCode = () =>
        router.post(
            `/signature/${token}/code`,
            {},
            { preserveScroll: true, preserveState: true, onStart: () => setSending(true), onFinish: () => setSending(false) },
        );
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/signature/${token}/sign`, { preserveScroll: true });
    };

    return (
        <AuthLayout title={`Signature${organisme ? ` - ${organisme}` : ''}`} description={title ?? ''}>
            <Head title="Signature" />
            <div className="grid gap-5">
                {status && <p className="text-sm font-medium text-green-600">{status}</p>}
                <Button asChild variant="outline">
                    <a href={`/signature/${token}/pdf`} target="_blank" rel="noopener">
                        <Eye className="h-4 w-4" />
                        {state === 'signed' ? 'Voir le document signé' : 'Lire le document'}
                    </a>
                </Button>

                {state === 'signed' ? (
                    <p className="rounded-md border p-3 text-sm">
                        Document signé le {signed_at}. Une copie vous a été envoyée par e-mail ; vous n'avez rien d'autre à faire.
                    </p>
                ) : (
                    <form onSubmit={submit} className="grid gap-4" noValidate>
                        <p className="text-muted-foreground text-sm">
                            {signer}, lisez le document, demandez votre code (envoyé à {email}), puis signez dans le cadre et validez. Ce lien est
                            valable jusqu'au {deadline}.
                        </p>
                        <Label className="flex items-start gap-2 font-normal">
                            <input type="checkbox" className="mt-1" checked={data.accept} onChange={(e) => setData('accept', e.target.checked)} />
                            J'ai lu le document et j'accepte de le signer électroniquement.
                        </Label>
                        <InputError message={errors.accept} />
                        <div className="grid gap-2">
                            <Button type="button" variant="outline" onClick={askCode} disabled={sending}>
                                {sending ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Mail className="h-4 w-4" />}
                                {code_sent ? 'Recevoir un nouveau code' : '1. Recevoir mon code par e-mail'}
                            </Button>
                            <InputError message={errs.message} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="code">Code à 6 chiffres</Label>
                            <Input
                                id="code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                maxLength={6}
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.replace(/\D/g, ''))}
                            />
                            <InputError message={errors.code} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="drawing">2. Votre signature</Label>
                            <p className="text-muted-foreground text-xs">Signez dans le cadre avec le doigt, la souris ou un stylet.</p>
                            <SignaturePad id="drawing" onChange={(uri) => setData('drawing', uri)} invalid={!!errors.drawing} />
                            <InputError message={errors.drawing} />
                        </div>
                        <Button type="submit" disabled={processing || data.code.length !== 6 || data.drawing === ''}>
                            {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <PenLine className="h-4 w-4" />}
                            Signer
                        </Button>
                        {!processing && (data.code.length !== 6 || data.drawing === '' || !data.accept) && (
                            <p className="text-muted-foreground text-center text-xs">
                                Pour signer :{' '}
                                {[
                                    !data.accept ? 'cochez la case' : null,
                                    data.code.length !== 6 ? 'saisissez le code à 6 chiffres' : null,
                                    data.drawing === '' ? 'dessinez votre signature' : null,
                                ]
                                    .filter(Boolean)
                                    .join(', ')}
                                .
                            </p>
                        )}
                    </form>
                )}
            </div>
        </AuthLayout>
    );
}
