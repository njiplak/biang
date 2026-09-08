import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    ChevronsUpDown,
    FileText,
    KeyRound,
    LayoutDashboard,
    LockKeyhole,
    LogOut,
    Megaphone,
    Settings,
    ScrollText,
    ShieldCheck,
    Tags,
    Timer,
    UserCog,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import FlashBanner from '@/components/flash-banner';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarTrigger,
    useSidebar,
} from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import admin from '@/routes/admin';
import type { SharedData, AppLayoutProps } from '@/types';

function getInitials(name: string) {
    return name
        .split(' ')
        .map((w) => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function SidebarUser() {
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const user = usePage<SharedData>().props.auth.admin;

    // Staff sit on the admin guard, so this is null on any customer page.
    if (!user) return null;

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent"
                        >
                            <Avatar className="h-8 w-8 rounded-full">
                                <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                    {getInitials(user.name)}
                                </AvatarFallback>
                            </Avatar>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-medium">
                                    {user.name}
                                </span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {user.email}
                                </span>
                            </div>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="end"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'left'
                                  : 'bottom'
                        }
                    >
                        <DropdownMenuLabel className="p-0 font-normal">
                            <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <Avatar className="h-8 w-8 rounded-full">
                                    <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                        {getInitials(user.name)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="grid flex-1 text-left text-sm leading-tight">
                                    <span className="truncate font-medium">
                                        {user.name}
                                    </span>
                                    <span className="truncate text-xs text-muted-foreground">
                                        {user.email}
                                    </span>
                                </div>
                            </div>
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {/*
                         * Two-factor is required of every staff account and
                         * was reachable only by typing the URL, which left
                         * re-enrolling on a new phone with nowhere to start.
                         */}
                        <DropdownMenuItem asChild>
                            <Link
                                className="block w-full cursor-pointer"
                                href={admin.twoFactor.edit.url()}
                            >
                                <LockKeyhole className="mr-2" />
                                Two-factor
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link
                                className="block w-full cursor-pointer"
                                href={admin.logout()}
                                as="button"
                            >
                                <LogOut className="mr-2" />
                                Log out
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

export default function AdminLayout({ children }: AppLayoutProps) {
    const page = usePage<SharedData>();
    const { sidebarOpen: isOpen } = page.props;
    const currentUrl = page.url;

    // `exact` matters for the dashboard: its href is `/admin`, which every
    // other staff screen is nested under, so a prefix match would light it up
    // on every page in the console.
    function isMenuActive(href: string, exact = false) {
        return exact
            ? currentUrl === href
            : currentUrl === href || currentUrl.startsWith(href + '/');
    }

    const { permissions } = page.props.auth;
    const can = (permission: string) => permissions.includes(permission);

    /*
     * Section 10 splits the console's jobs across roles, and the sidebar has
     * to split with them. Every item used to be shown to every staff member,
     * so finance clicked "Plans" and got a 403 - the menu advertised work the
     * console would then refuse.
     *
     * Each `permission` here is the one its own route group is gated on in
     * routes/web/admin.php and routes/web/setting.php. Dashboard carries none:
     * it is the landing page for the guard, and its only privileged half - the
     * revenue figures - is already withheld by DashboardController.
     *
     * super-admin is not a special case. AdminRoleSeeder syncs the role with
     * every permission, so the Gate::before bypass never has to be mirrored
     * here.
     */
    const menu: {
        href: string;
        label: string;
        icon: LucideIcon;
        permission?: string;
        exact?: boolean;
    }[] = [
        {
            href: admin.dashboard.url(),
            label: 'Dashboard',
            icon: LayoutDashboard,
            exact: true,
        },
        {
            href: admin.customer.index.url(),
            label: 'Customers',
            icon: Building2,
            permission: 'customer.view',
        },
        {
            href: admin.catalog.index.url(),
            label: 'Plans',
            icon: Tags,
            permission: 'plan.manage',
        },
        {
            href: admin.announcement.index.url(),
            label: 'Announcements',
            icon: Megaphone,
            permission: 'announcement.manage',
        },
        {
            href: admin.page.index.url(),
            label: 'Pages',
            icon: FileText,
            permission: 'page.view',
        },
        {
            href: admin.billingOps.index.url(),
            label: 'Billing ops',
            icon: Wallet,
            permission: 'revenue.view',
        },
        {
            href: admin.staff.index.url(),
            label: 'Staff',
            icon: ShieldCheck,
            permission: 'staff.manage',
        },
        {
            href: admin.audit.index.url(),
            label: 'Audit trail',
            icon: ScrollText,
            permission: 'customer.view',
        },
        {
            href: admin.scheduler.index.url(),
            label: 'Scheduled tasks',
            icon: Timer,
            permission: 'setting.view',
        },
        /*
         * Roles and permissions decide what every other item here is worth,
         * and both had routes and screens that nothing linked to - editing a
         * staff role meant knowing the URL by heart.
         */
        {
            href: admin.setting.role.index.url(),
            label: 'Roles',
            icon: UserCog,
            permission: 'role.view',
        },
        {
            href: admin.setting.permission.index.url(),
            label: 'Permissions',
            icon: KeyRound,
            permission: 'permission.view',
        },
        {
            href: admin.setting.setting.index.url(),
            label: 'Settings',
            icon: Settings,
            permission: 'setting.view',
        },
    ].filter((item) => item.permission === undefined || can(item.permission));

    return (
        <SidebarProvider defaultOpen={isOpen}>
            <Sidebar collapsible="icon" variant="sidebar">
                <SidebarHeader>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton size="lg" asChild>
                                <Link href={admin.dashboard.url()} prefetch>
                                    <AppLogo />
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarHeader>
                <SidebarContent>
                    <SidebarGroup>
                        <SidebarGroupLabel>Menu</SidebarGroupLabel>
                        <SidebarGroupContent>
                            <SidebarMenu>
                                {menu.map((item) => (
                                    <SidebarMenuItem key={item.href}>
                                        <SidebarMenuButton
                                            asChild
                                            isActive={isMenuActive(
                                                item.href,
                                                item.exact,
                                            )}
                                        >
                                            <Link href={item.href}>
                                                <item.icon />
                                                <span>{item.label}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                ))}
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                </SidebarContent>
                <SidebarFooter>
                    <SidebarUser />
                </SidebarFooter>
            </Sidebar>
            <SidebarInset className="overflow-x-hidden">
                <header className="flex shrink-0 items-center gap-2 border-b px-4 py-2.5 sm:px-6 sm:py-3">
                    <SidebarTrigger />
                    <Separator orientation="vertical" className="mx-1 h-5" />
                    <h1 className="text-sm font-semibold tracking-tight sm:text-base">
                        {page.props.name}
                    </h1>
                </header>
                <div className="flex flex-1 flex-col gap-4 p-3 sm:p-4 md:p-6">
                    {/*
                     * The console redirects staff without explaining itself
                     * otherwise - EnsureAdminTwoFactor bounces them to
                     * enrolment, and the reason it did is flashed on the
                     * request that redirected, not the page that renders.
                     */}
                    <FlashBanner />
                    {children}
                </div>
            </SidebarInset>
        </SidebarProvider>
    );
}
