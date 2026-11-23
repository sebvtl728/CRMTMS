import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface ConnectorMeta {
    key: string;
    label: string;
    kind: string;
    organisme_wide: boolean;
    settings: string[];
    secrets: string[];
    labels: Record<string, string>;
    fields: Record<string, FieldMeta>;
}

interface FieldMeta {
    type: 'select' | 'checkbox' | 'text';
    options?: { value: string; label: string }[];
    default?: string | boolean;
    suggestions?: string[];
    help?: string;
    warning?: string;
    hidden?: boolean;
    visible_when?: Record<string, string[]>;
}

type SettingValue = string | boolean;

interface EditProps {
    connector: ConnectorMeta;
    companyId: string | null;
    companies: { id: string; name: string }[];
    config: { id: string; enabled: boolean; settings: Record<string, string | number | boolean | null>; secrets_set: Record<string, boolean> } | null;
    status?: string;
}

const TENANT_WIDE = '__all__';

const encryptionOptions = [
    { value: 'tls', label: 'TLS (STARTTLS, port 587)' },
    { value: 'ssl', label: 'SSL (port 465)' },
    { value: 'none', label: 'Aucun' },
];

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function initialSetting(key: string, field: FieldMeta | undefined, saved: string | number | boolean | null | undefined): SettingValue {
    if (field?.type === 'checkbox') {
        return saved === true || saved === 1 || saved === '1' || (saved == null && field.default === true);
    }
    if (saved != null) {
        return String(saved);
    }
    if (typeof field?.default === 'string') {
        return field.default;
    }

    return key === 'port' ? '587' : key === 'encryption' ? 'tls' : '';
}

export default function EditConnector({ connector, companyId, companies, config }: EditProps) {
    const label = (key: string) => connector.labels[key] ?? key;
    const field = (key: string): FieldMeta | undefined => connector.fields[key];

    const { data, setData, put, processing, errors, transform } = useForm<{
        company_id: string | null;
        enabled: boolean;
        settings: Record<string, SettingValue>;
        secrets: Record<string, string>;
    }>({
        company_id: companyId,
        enabled: config?.enabled ?? true,
        settings: Object.fromEntries(connector.settings.map((key) => [key, initialSetting(key, field(key), config?.settings[key])])),
        // Secrets are write-only: the fields start empty.
        secrets: Object.fromEntries(connector.secrets.map((key) => [key, ''])),
    });

    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Connecteurs', href: '/admin/connectors' },
        { title: connector.label, href: '#' },
    ];

    // Settings errors come back keyed "settings.host".
    const error = (group: 'settings' | 'secrets', key: string) => (errors as Record<string, string | undefined>)[`${group}.${key}`];

    // A field may only make sense for some values of another setting (e.g. the address of an OpenAI-compatible server).
    const visible = (key: string) =>
        !field(key)?.hidden &&
        Object.entries(field(key)?.visible_when ?? {}).every(([other, values]) => values.includes(String(data.settings[other] ?? '')));
    const setSetting = (key: string, value: SettingValue) => setData('settings', { ...data.settings, [key]: value });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // Hidden fields are neither validated nor stored (e.g. the address for Anthropic).
        transform((current) => ({ ...current, settings: Object.fromEntries(Object.entries(current.settings).filter(([key]) => visible(key))) }));
        put(route('admin.connectors.update', connector.key), {
            preserveScroll: true,
            onSuccess: () => setData('secrets', Object.fromEntries(connector.secrets.map((key) => [key, '']))),
        });
    };

    const test = async () => {
        setTesting(true);
        setResult(null);
        try {
            const response = await fetch(route('admin.connectors.test', { key: connector.key, company: companyId ?? undefined }), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            });
            const json = await response.json();
            setResult({ ok: Boolean(json.ok), message: json.message ?? 'Réponse inattendue.' });
        } catch {
            setResult({ ok: false, message: "Le test n'a pas pu être lancé." });
        } finally {
            setTesting(false);
        }
    };

    const clearSecret = (key: string) => {
        if (confirm(`Effacer « ${label(key)} » ?`)) {
            router.delete(route('admin.connectors.secrets.clear', { key: connector.key, secret: key, company: companyId ?? undefined }), {
                preserveScroll: true,
            });
        }
    };

    const switchScope = (value: string) => {
        router.get(route('admin.connectors.edit', { key: connector.key, company: value === TENANT_WIDE ? undefined : value }));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={connector.label} />

            <div className="max-w-2xl p-4">
                <Heading title={connector.label} description="Les secrets sont chiffrés et ne sont jamais réaffichés." />

                {!connector.organisme_wide && (
                    <div className="mb-6 grid gap-2">
                        <Label htmlFor="scope">Portée</Label>
                        <Select value={companyId ?? TENANT_WIDE} onValueChange={switchScope}>
                            <SelectTrigger id="scope">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={TENANT_WIDE}>Tout l'organisme (par défaut)</SelectItem>
                                {companies.map((company) => (
                                    <SelectItem key={company.id} value={company.id}>
                                        {company.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.company_id} />
                    </div>
                )}

                <form onSubmit={submit} className="space-y-6">
                    <div className="flex items-center gap-2">
                        <Checkbox id="enabled" checked={data.enabled} onCheckedChange={(checked) => setData('enabled', checked === true)} />
                        <Label htmlFor="enabled">Connecteur activé</Label>
                    </div>

                    {connector.settings.filter(visible).map((key) => {
                        const meta = field(key);
                        const value = data.settings[key];

                        if (meta?.type === 'checkbox') {
                            return (
                                <div key={key} className="grid gap-2">
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id={key}
                                            checked={value === true}
                                            onCheckedChange={(checked) => setSetting(key, checked === true)}
                                        />
                                        <Label htmlFor={key}>{label(key)}</Label>
                                    </div>
                                    {meta.warning && (
                                        <p
                                            role="note"
                                            className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
                                        >
                                            {meta.warning}
                                        </p>
                                    )}
                                    <InputError message={error('settings', key)} />
                                </div>
                            );
                        }

                        const options = meta?.type === 'select' ? meta.options : key === 'encryption' ? encryptionOptions : undefined;

                        return (
                            <div key={key} className="grid gap-2">
                                <Label htmlFor={key}>{label(key)}</Label>
                                {options ? (
                                    <Select value={String(value)} onValueChange={(selected) => setSetting(key, selected)}>
                                        <SelectTrigger id={key}>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {options.map((option) => (
                                                <SelectItem key={option.value} value={option.value}>
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <>
                                        <Input
                                            id={key}
                                            value={String(value)}
                                            inputMode={key === 'port' ? 'numeric' : undefined}
                                            list={meta?.suggestions ? `${key}-suggestions` : undefined}
                                            onChange={(e) => setSetting(key, e.target.value)}
                                        />
                                        {meta?.suggestions && (
                                            <datalist id={`${key}-suggestions`}>
                                                {meta.suggestions.map((suggestion) => (
                                                    <option key={suggestion} value={suggestion} />
                                                ))}
                                            </datalist>
                                        )}
                                    </>
                                )}
                                {meta?.help && <p className="text-muted-foreground text-xs">{meta.help}</p>}
                                <InputError message={error('settings', key)} />
                            </div>
                        );
                    })}

                    {connector.secrets.map((key) => {
                        const isSet = config?.secrets_set[key] ?? false;

                        return (
                            <div key={key} className="grid gap-2">
                                <Label htmlFor={`secret-${key}`}>{label(key)}</Label>
                                <div className="flex items-center gap-2">
                                    <Input
                                        id={`secret-${key}`}
                                        type="password"
                                        autoComplete="new-password"
                                        value={data.secrets[key]}
                                        placeholder={isSet ? '•••• défini (laisser vide pour conserver)' : 'Non défini'}
                                        onChange={(e) => setData('secrets', { ...data.secrets, [key]: e.target.value })}
                                    />
                                    {isSet && (
                                        <Button type="button" variant="outline" onClick={() => clearSecret(key)}>
                                            Effacer
                                        </Button>
                                    )}
                                </div>
                                {field(key)?.help && <p className="text-muted-foreground text-xs">{field(key)?.help}</p>}
                                <InputError message={error('secrets', key)} />
                            </div>
                        );
                    })}

                    <div className="flex flex-wrap items-center gap-4">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Enregistrer
                        </Button>
                        <Button type="button" variant="outline" disabled={testing || !config} onClick={test}>
                            {testing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                            Tester la connexion
                        </Button>
                        <Link href={route('admin.connectors.index')} className="text-muted-foreground text-sm underline-offset-4 hover:underline">
                            Retour
                        </Link>
                    </div>
                    {!config && <p className="text-muted-foreground text-xs">Enregistrez la configuration pour pouvoir la tester.</p>}

                    {result && (
                        <div
                            role="status"
                            aria-live="polite"
                            className={`rounded-md border p-3 text-sm ${result.ok ? 'text-green-700' : 'text-red-700'}`}
                        >
                            {result.message}
                        </div>
                    )}
                </form>
            </div>
        </AppLayout>
    );
}
