import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Building2, Contact, Factory, Handshake, History, LayoutGrid, ListChecks, Plug, ShieldAlert, ShieldCheck, Upload, Users } from 'lucide-react';
import AppLogo from './app-logo';

const homeNavItems: NavItem[] = [{ title: 'Accueil', url: '/dashboard', icon: LayoutGrid }];

const commercialNavItems: (NavItem & { permission: string })[] = [
    { title: 'Entreprises', url: '/crm/organisations', icon: Factory, permission: 'crm.view' },
    { title: 'Personnes', url: '/crm/people', icon: Contact, permission: 'crm.view' },
    { title: 'Opportunités', url: '/crm/opportunities', icon: Handshake, permission: 'crm.view' },
    { title: 'Imports', url: '/crm/import', icon: Upload, permission: 'crm.import' },
    { title: 'RGPD', url: '/crm/gdpr', icon: ShieldAlert, permission: 'crm.gdpr' },
];

// Each entry is shown only when the user holds the permission (the server stays the authority).
const adminNavItems: (NavItem & { permission: string })[] = [
    { title: 'Sociétés', url: '/admin/companies', icon: Building2, permission: 'companies.manage' },
    { title: 'Utilisateurs', url: '/admin/users', icon: Users, permission: 'users.manage' },
    { title: 'Référentiels', url: '/admin/references', icon: ListChecks, permission: 'references.manage' },
    { title: 'Connecteurs', url: '/admin/connectors', icon: Plug, permission: 'connectors.manage' },
    { title: "Journal d'audit", url: '/admin/audit', icon: History, permission: 'audit.view' },
    { title: 'Sécurité', url: '/admin/security', icon: ShieldCheck, permission: 'settings.manage' },
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const commercialItems = commercialNavItems.filter((item) => auth.permissions.includes(item.permission));
    const adminItems = adminNavItems.filter((item) => auth.permissions.includes(item.permission));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={homeNavItems} label="Navigation" />
                {commercialItems.length > 0 && <NavMain items={commercialItems} label="Commercial" />}
                {adminItems.length > 0 && <NavMain items={adminItems} label="Administration" />}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
