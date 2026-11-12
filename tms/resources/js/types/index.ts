import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    /** Permission names held by the user (e.g. "users.manage"). */
    permissions: string[];
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
}

export interface SharedData {
    name: string;
    auth: Auth;
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
