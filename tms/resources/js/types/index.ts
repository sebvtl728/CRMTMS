import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    /** Permission names held by the user (e.g. "users.manage"). */
    permissions: string[];
    /** Every right on « Ses séances » only (a trainer): the menu is « Mon planning » alone. */
    trainer_only: boolean;
    /** Linked to a trainer: « Mon planning » in the menu. */
    my_planning: boolean;
    /** « Mon agenda » (iCal feed): CRM actions or séances to publish. */
    calendar_feed: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Count shown beside the entry (hidden at 0). */
    badge?: number;
    /** Accessible text of the count (default « N à faire »). */
    badgeLabel?: string;
    /** The count in red (work waiting for the user). */
    badgeAlert?: boolean;
    /** A red dot on the entry (e.g. backup in trouble); the text is its accessible label. */
    alert?: string | null;
}

export interface SharedData {
    name: string;
    /** Organisme identity (default company), null for guests. */
    brand: { name: string; logoUrl: string | null } | null;
    auth: Auth;
    /** Menu badge of « À faire »: overdue + today. */
    todo_count: number;
    candidates_badge: number;
    /** « À traiter »: the total of the menu, null when the user has no source. */
    todo_total: number | null;
    sandbox: boolean;
    /** The « Sauvegardes » page exists (self-hosted install). */
    backups_enabled: boolean;
    /** French banner text about the backups (settings.manage, single mode), null when all is well. */
    backup_alert: string | null;
    [key: string]: unknown;
}

export interface User {
    id: string;
    name: string;
    email: string;
    email_verified_at: string | null;
    /** Role label, null without role. */
    role: string | null;
    two_factor_enabled: boolean;
    avatar?: string;
}
