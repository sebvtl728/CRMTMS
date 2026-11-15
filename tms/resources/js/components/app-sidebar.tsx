import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Building2,
    CalendarCheck,
    CalendarRange,
    ClipboardPen,
    Columns3,
    Contact,
    Factory,
    FileText,
    Handshake,
    History,
    KeyRound,
    LayoutGrid,
    ListChecks,
    Mail,
    Plug,
    ShieldAlert,
    ShieldCheck,
    Upload,
    Users,
} from 'lucide-react';
import AppLogo from './app-logo';

const homeNavItems: NavItem[] = [{ title: 'Accueil', url: '/dashboard', icon: LayoutGrid }];

// « Commercial », in the order of a working day: the pipeline and its quotes, the actions to do, the catalogue, then the fiches.
const commercialNavItems: (NavItem & { permission: string })[] = [
    { title: 'Pipeline', url: '/crm/opportunities/kanban', icon: Columns3, permission: 'crm.opportunities.view' },
    { title: 'Opportunités', url: '/crm/opportunities', icon: Handshake, permission: 'crm.opportunities.view' },
    { title: 'Devis', url: '/crm/quotes', icon: FileText, permission: 'crm.quotes.view' },
    { title: 'À faire', url: '/crm/todo', icon: CalendarCheck, permission: 'crm.activities.view' },
    { title: 'Planning', url: '/crm/planning', icon: CalendarRange, permission: 'crm.activities.view' },
    { title: 'Compte rendu de RDV', url: '/crm/report', icon: ClipboardPen, permission: 'crm.activities.update' },
    { title: 'Catalogue', url: '/catalogue', icon: BookOpen, permission: 'catalogue.view' },
    { title: 'Entreprises', url: '/crm/organisations', icon: Factory, permission: 'crm.fiches.view' },
    { title: 'Personnes', url: '/crm/people', icon: Contact, permission: 'crm.fiches.view' },
    { title: 'Imports', url: '/crm/import', icon: Upload, permission: 'crm.import' },
    { title: 'RGPD', url: '/crm/gdpr', icon: ShieldAlert, permission: 'crm.gdpr' },
];

// Each entry is shown only when the user holds the permission (the server stays the authority).
const adminNavItems: (NavItem & { permission: string })[] = [
    { title: 'Sociétés', url: '/admin/companies', icon: Building2, permission: 'companies.manage' },
    { title: 'Utilisateurs', url: '/admin/users', icon: Users, permission: 'users.manage' },
    { title: 'Rôles et droits', url: '/admin/roles', icon: KeyRound, permission: 'roles.manage' },
    { title: 'Référentiels', url: '/admin/references', icon: ListChecks, permission: 'references.manage' },
    { title: 'Connecteurs', url: '/admin/connectors', icon: Plug, permission: 'connectors.manage' },
    { title: "Journal d'audit", url: '/admin/audit', icon: History, permission: 'audit.view' },
    { title: 'Sécurité', url: '/admin/security', icon: ShieldCheck, permission: 'settings.manage' },
    { title: "Modèles d'e-mails", url: '/admin/mail-templates', icon: Mail, permission: 'settings.manage' },
];

export function AppSidebar() {
    const { auth, todo_count } = usePage<SharedData>().props;
    const commercialItems = commercialNavItems
        .filter((item) => auth.permissions.includes(item.permission))
        .map((item) => (item.url === '/crm/todo' ? { ...item, badge: todo_count } : item));
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
