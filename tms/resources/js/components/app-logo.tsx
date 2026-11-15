import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import AppLogoIcon from './app-logo-icon';

/** Organisme identity of the signed-in user: name of the default company, else the application name. */
export function useBrand(): { name: string; logoUrl: string | null } {
    const { name, brand } = usePage<SharedData>().props;

    return { name: brand?.name || name, logoUrl: brand?.logoUrl ?? null };
}

/** Logo of the default company (Administration > Sociétés), else the generic icon. */
export function BrandMark({ className = 'size-8' }: { className?: string }) {
    const { logoUrl } = useBrand();

    if (logoUrl) {
        return <img src={logoUrl} alt="" className={`${className} shrink-0 rounded-md bg-white object-contain`} data-testid="brand-logo" />;
    }

    return (
        <div
            className={`bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square ${className} shrink-0 items-center justify-center rounded-md`}
        >
            <AppLogoIcon className="size-5 fill-current text-white dark:text-black" />
        </div>
    );
}

export default function AppLogo() {
    const { name } = useBrand();

    return (
        <>
            <BrandMark />
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold" data-testid="brand-name" title={name}>
                    {name}
                </span>
            </div>
        </>
    );
}
