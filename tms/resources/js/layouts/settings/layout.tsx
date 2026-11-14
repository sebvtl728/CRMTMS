import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profil',
        url: '/settings/profile',
        icon: null,
    },
    {
        title: 'Mot de passe',
        url: '/settings/password',
        icon: null,
    },
    {
        title: 'Double authentification',
        url: '/two-factor/setup',
        icon: null,
    },
    {
        title: 'Apparence',
        url: '/settings/appearance',
        icon: null,
    },
];

// The iCal feed lists CRM meetings and calls: for the users of the CRM only.
const calendarNavItem: NavItem = {
    title: 'Mon agenda',
    url: '/settings/calendar',
    icon: null,
};

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const currentPath = window.location.pathname;
    const { auth } = usePage<SharedData>().props;
    const navItems = auth.permissions.includes('crm.view') ? [...sidebarNavItems, calendarNavItem] : sidebarNavItems;

    return (
        <div className="px-4 py-6">
            <Heading title="Paramètres" description="Gérez votre profil et la sécurité de votre compte" />

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {navItems.map((item) => (
                            <Button
                                key={item.url}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': currentPath === item.url,
                                })}
                            >
                                <Link href={item.url} prefetch>
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
