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
    BookOpenCheck,
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
    Gauge,
    GraduationCap,
    HandCoins,
    Handshake,
    History,
    KeyRound,
    LayoutGrid,
    ListChecks,
    Mail,
    MessageSquareWarning,
    Plug,
    ReceiptEuro,
    RotateCcw,
    Settings2,
    ShieldAlert,
    ShieldCheck,
    SlidersHorizontal,
    Smile,
    TrendingUp,
    Upload,
    UserMinus,
    UserRoundCheck,
    Users,
    Workflow,
} from 'lucide-react';
import AppLogo from './app-logo';

const homeNavItems: NavItem[] = [{ title: 'Accueil', url: '/dashboard', icon: LayoutGrid }];

// « Commercial », in the order of a working day: the pipeline and its quotes, the actions to do, the catalogue, then the fiches.
const commercialNavItems: (NavItem & { permission: string })[] = [
    { title: 'Pipeline', url: '/crm/opportunities/kanban', icon: Columns3, permission: 'crm.opportunities.view' },
    { title: 'Opportunités', url: '/crm/opportunities', icon: Handshake, permission: 'crm.opportunities.view' },
    { title: 'Devis', url: '/crm/quotes', icon: FileText, permission: 'crm.quotes.view' },
    { title: "Chiffre d'affaires", url: '/crm/revenue', icon: Euro, permission: 'crm.revenue.view' },
    { title: 'Mes actions commerciales', url: '/crm/todo', icon: CalendarCheck, permission: 'crm.activities.view' },
    { title: 'Tableaux de bord', url: '/crm/dashboards', icon: Gauge, permission: 'crm.dashboards.view' },
    { title: 'Agenda commercial', url: '/crm/planning', icon: CalendarRange, permission: 'crm.activities.view' },
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
    { title: 'Réclamations', url: '/qualite/reclamations', icon: MessageSquareWarning, permission: 'qualite.view' },
    { title: 'Amélioration continue', url: '/qualite/amelioration', icon: TrendingUp, permission: 'qualite.view' },
    { title: 'Résultats des questionnaires', url: '/qualite/questionnaires', icon: Smile, permission: 'questionnaires.view' },
];

const myPlanningItem: NavItem = { title: 'Mon planning', url: '/mon-planning', icon: CalendarClock };

// Administration, in four sections. Each entry is shown only when the user holds the permission (the server stays the authority).
type AdminSection = 'organisme' | 'reglages' | 'modeles' | 'technique';
const adminSections: { key: AdminSection; label: string }[] = [
    { key: 'organisme', label: 'Organisme' },
    { key: 'reglages', label: 'Listes et réglages' },
    { key: 'modeles', label: 'Modèles' },
    { key: 'technique', label: 'Technique et sécurité' },
];
const adminNavItems: (NavItem & { permission: string; section: AdminSection })[] = [
    { title: 'Sociétés', url: '/admin/companies', icon: Building2, permission: 'companies.manage', section: 'organisme' },
    { title: 'Utilisateurs', url: '/admin/users', icon: Users, permission: 'users.manage', section: 'organisme' },
    { title: 'Rôles et droits', url: '/admin/roles', icon: KeyRound, permission: 'roles.manage', section: 'organisme' },
    { title: 'Référentiels', url: '/admin/references', icon: ListChecks, permission: 'references.manage', section: 'reglages' },
    { title: 'Champs personnalisés', url: '/admin/custom-fields', icon: SlidersHorizontal, permission: 'settings.manage', section: 'reglages' },
    { title: 'Réglages du CRM', url: '/admin/crm-settings', icon: Settings2, permission: 'settings.manage', section: 'reglages' },
    { title: "Règle d'abandon et suivi", url: '/admin/abandon-rule', icon: UserMinus, permission: 'settings.manage', section: 'reglages' },
    { title: 'Salles et fermetures', url: '/admin/sessions-settings', icon: DoorOpen, permission: 'sessions.rooms.manage', section: 'reglages' },
    { title: 'Automatismes', url: '/admin/automation', icon: Workflow, permission: 'settings.manage', section: 'reglages' },
    { title: "Livret d'apprentissage", url: '/admin/livret-apprentissage', icon: BookOpenCheck, permission: 'settings.manage', section: 'reglages' },
    { title: "Modèles d'e-mails", url: '/admin/mail-templates', icon: Mail, permission: 'settings.manage', section: 'modeles' },
    { title: 'Modèles de documents', url: '/admin/document-texts', icon: FileSignature, permission: 'settings.manage', section: 'modeles' },
    { title: 'Modèles de questionnaires', url: '/admin/questionnaires', icon: Smile, permission: 'questionnaires.manage', section: 'modeles' },
    { title: 'Connecteurs', url: '/admin/connectors', icon: Plug, permission: 'connectors.manage', section: 'technique' },
    { title: 'Sécurité', url: '/admin/security', icon: ShieldCheck, permission: 'settings.manage', section: 'technique' },
    { title: 'Sauvegardes', url: '/admin/backups', icon: DatabaseBackup, permission: 'settings.manage', section: 'technique' },
    { title: "Journal d'audit", url: '/admin/audit', icon: History, permission: 'audit.view', section: 'technique' },
    // Local sandbox only (shown when the shared prop « sandbox » is true; never on a server).
    { title: 'Bac à sable', url: '/admin/sandbox', icon: RotateCcw, permission: 'settings.manage', section: 'technique' },
];

export function AppSidebar() {
    const { auth, todo_count, todo_menu, todo_total, candidates_badge, backups_enabled, backup_alert, sandbox } = usePage<SharedData>().props;
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
                        <NavMain
                            items={[
                                ...homeNavItems,
                                ...(todo_menu ? [{ title: 'À traiter', url: '/a-traiter', icon: ListChecks, badge: todo_total }] : []),
                            ]}
                            label="Navigation"
                        />
                        {commercialItems.length > 0 && <NavMain items={commercialItems} label="Commercial" />}
                        {trainingItems.length > 0 && <NavMain items={trainingItems} label="Formation" />}
                        {financeItems.length > 0 && <NavMain items={financeItems} label="Finance" />}
                        {qualityItems.length > 0 && <NavMain items={qualityItems} label="Qualité" />}
                        {adminSections.map((section) => {
                            const items = adminItems.filter((item) => item.section === section.key);

                            return items.length > 0 && <NavMain key={section.key} items={items} label={`Admin · ${section.label}`} />;
                        })}
                    </>
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
