import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    Banknote,
    BellRing,
    BookOpen,
    Building2,
    Calculator,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    ClipboardCheck,
    ClipboardList,
    ClipboardPen,
    Columns3,
    Contact,
    DatabaseBackup,
    DoorOpen,
    Euro,
    Factory,
    FileSignature,
    FileText,
    HandCoins,
    Gauge,
    GraduationCap,
    Handshake,
    History,
    KeyRound,
    LayoutGrid,
    ListChecks,
    Mail,
    Plug,
    ReceiptEuro,
    RotateCcw,
    Settings2,
    ShieldAlert,
    ShieldCheck,
    SlidersHorizontal,
    Smile,
    Upload,
    UserMinus,
    UserRoundCheck,
    Users,
} from 'lucide-react';
import AppLogo from './app-logo';

const homeNavItems: NavItem[] = [{ title: 'Accueil', url: '/dashboard', icon: LayoutGrid }];

// « Commercial », in the order of a working day: the pipeline and its quotes, the actions to do, the catalogue, then the fiches.
const commercialNavItems: (NavItem & { permission: string })[] = [
    { title: 'Pipeline', url: '/crm/opportunities/kanban', icon: Columns3, permission: 'crm.opportunities.view' },
    { title: 'Opportunités', url: '/crm/opportunities', icon: Handshake, permission: 'crm.opportunities.view' },
    { title: 'Devis', url: '/crm/quotes', icon: FileText, permission: 'crm.quotes.view' },
    { title: "Chiffre d'affaires", url: '/crm/revenue', icon: Euro, permission: 'crm.revenue.view' },
    { title: 'À faire', url: '/crm/todo', icon: CalendarCheck, permission: 'crm.activities.view' },
    { title: 'Tableaux de bord', url: '/crm/dashboards', icon: Gauge, permission: 'crm.dashboards.view' },
    { title: 'Planning', url: '/crm/planning', icon: CalendarRange, permission: 'crm.activities.view' },
    { title: 'Compte rendu de RDV', url: '/crm/report', icon: ClipboardPen, permission: 'crm.activities.update' },
    { title: 'Catalogue', url: '/catalogue', icon: BookOpen, permission: 'catalogue.view' },
    { title: 'Entreprises', url: '/crm/organisations', icon: Factory, permission: 'crm.fiches.view' },
    { title: 'Interlocuteurs', url: '/crm/people', icon: Contact, permission: 'crm.fiches.view' },
    { title: 'Imports', url: '/crm/import', icon: Upload, permission: 'crm.import' },
    { title: 'RGPD', url: '/crm/gdpr', icon: ShieldAlert, permission: 'crm.gdpr' },
];

// « Formation »: the operational side (candidates, dossiers, sessions, then enrolments).
const trainingNavItems: (NavItem & { permission: string })[] = [
    { title: 'Candidats', url: '/admissions/candidatures', icon: UserRoundCheck, permission: 'admissions.candidatures.view' },
    { title: 'Dossiers', url: '/dossiers', icon: ClipboardList, permission: 'sessions.dossiers.view' },
    { title: 'Sessions', url: '/sessions', icon: CalendarDays, permission: 'sessions.sessions.view' },
    { title: 'Planning des séances', url: '/sessions/planning', icon: CalendarRange, permission: 'sessions.seances.view' },
    { title: 'Formateurs', url: '/trainers', icon: GraduationCap, permission: 'sessions.trainers.manage' },
    { title: 'Tests en ligne', url: '/admissions/online-tests', icon: ClipboardCheck, permission: 'catalogue.manage' },
];

// « Finance »: invoicing (0.9).
const financeNavItems: (NavItem & { permission: string })[] = [
    { title: 'À facturer', url: '/finance/to-invoice', icon: Calculator, permission: 'finance.invoices.view' },
    { title: 'Factures', url: '/finance/invoices', icon: ReceiptEuro, permission: 'finance.invoices.view' },
    { title: 'À relancer', url: '/finance/reminders', icon: BellRing, permission: 'finance.invoices.manage' },
    { title: 'Règlements', url: '/finance/payments', icon: Banknote, permission: 'finance.payments.manage' },
    { title: 'À reverser', url: '/finance/payouts', icon: HandCoins, permission: 'finance.payments.manage' },
    { title: 'Réglages de facturation', url: '/finance/settings', icon: Settings2, permission: 'settings.manage' },
];

// « Qualité »: the Qualiopi file.
const qualityNavItems: (NavItem & { permission: string })[] = [
    { title: 'Qualiopi', url: '/qualite/qualiopi', icon: BadgeCheck, permission: 'qualite.view' },
    { title: 'Questionnaires', url: '/qualite/questionnaires', icon: Smile, permission: 'questionnaires.view' },
];

const myPlanningItem: NavItem = { title: 'Mon planning', url: '/mon-planning', icon: CalendarClock };

// Each entry is shown only when the user holds the permission (the server stays the authority).
const adminNavItems: (NavItem & { permission: string })[] = [
    { title: 'Sociétés', url: '/admin/companies', icon: Building2, permission: 'companies.manage' },
    { title: 'Utilisateurs', url: '/admin/users', icon: Users, permission: 'users.manage' },
    { title: 'Rôles et droits', url: '/admin/roles', icon: KeyRound, permission: 'roles.manage' },
    { title: 'Référentiels', url: '/admin/references', icon: ListChecks, permission: 'references.manage' },
    { title: 'Connecteurs', url: '/admin/connectors', icon: Plug, permission: 'connectors.manage' },
    { title: "Journal d'audit", url: '/admin/audit', icon: History, permission: 'audit.view' },
    { title: 'Sécurité', url: '/admin/security', icon: ShieldCheck, permission: 'settings.manage' },
    { title: 'Champs personnalisés', url: '/admin/custom-fields', icon: SlidersHorizontal, permission: 'settings.manage' },
    { title: 'Sauvegardes', url: '/admin/backups', icon: DatabaseBackup, permission: 'settings.manage' },
    { title: "Règle d'abandon et suivi", url: '/admin/abandon-rule', icon: UserMinus, permission: 'settings.manage' },
    { title: 'Réglages du CRM', url: '/admin/crm-settings', icon: Settings2, permission: 'settings.manage' },
    { title: 'Salles et fermetures', url: '/admin/sessions-settings', icon: DoorOpen, permission: 'sessions.rooms.manage' },
    { title: "Modèles d'e-mails", url: '/admin/mail-templates', icon: Mail, permission: 'settings.manage' },
    { title: 'Modèles de documents', url: '/admin/document-texts', icon: FileSignature, permission: 'settings.manage' },
    { title: 'Questionnaires', url: '/admin/questionnaires', icon: Smile, permission: 'questionnaires.manage' },
    // Local sandbox only (shown when the shared prop « sandbox » is true; never on a server).
    { title: 'Bac à sable', url: '/admin/sandbox', icon: RotateCcw, permission: 'settings.manage' },
];

export function AppSidebar() {
    const { auth, todo_count, candidates_badge, backups_enabled, backup_alert, sandbox } = usePage<SharedData>().props;
    const commercialItems = commercialNavItems
        .filter((item) => auth.permissions.includes(item.permission))
        .map((item) => (item.url === '/crm/todo' ? { ...item, badge: todo_count } : item));
    const trainingItems: NavItem[] = [
        ...(auth.my_planning ? [myPlanningItem] : []),
        ...trainingNavItems
            .filter((item) => auth.permissions.includes(item.permission))
            // A DAS responsible: the candidatures of his DAS still « Identifié », in red.
            .map((item) =>
                item.url === '/admissions/candidatures'
                    ? {
                          ...item,
                          badge: candidates_badge,
                          badgeAlert: true,
                          badgeLabel: `${candidates_badge} candidature(s) identifiée(s) dans vos DAS`,
                      }
                    : item,
            ),
    ];
    const financeItems = financeNavItems.filter((item) => auth.permissions.includes(item.permission));
    const qualityItems = qualityNavItems.filter((item) => auth.permissions.includes(item.permission));
    const adminItems = adminNavItems
        .filter(
            (item) =>
                auth.permissions.includes(item.permission) &&
                (item.url !== '/admin/backups' || backups_enabled) &&
                (item.url !== '/admin/sandbox' || sandbox),
        )
        // Backup in trouble: a red dot on « Sauvegardes » for the administrator (never on the home page).
        .map((item) => (item.url === '/admin/backups' ? { ...item, alert: backup_alert } : item));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={auth.trainer_only ? '/mon-planning' : '/dashboard'} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {auth.trainer_only ? (
                    // A trainer and nothing else: his planning, and his follow-up calls when he holds them.
                    <NavMain items={[myPlanningItem, ...commercialItems.filter((item) => item.url === '/crm/todo')]} label="Navigation" />
                ) : (
                    <>
                        <NavMain items={homeNavItems} label="Navigation" />
                        {commercialItems.length > 0 && <NavMain items={commercialItems} label="Commercial" />}
                        {trainingItems.length > 0 && <NavMain items={trainingItems} label="Formation" />}
                        {financeItems.length > 0 && <NavMain items={financeItems} label="Finance" />}
                        {qualityItems.length > 0 && <NavMain items={qualityItems} label="Qualité" />}
                        {adminItems.length > 0 && <NavMain items={adminItems} label="Administration" />}
                    </>
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
