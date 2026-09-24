import { Link, usePage } from '@inertiajs/react';
import {
    CreditCard,
    FolderKanban,
    LayoutDashboard,
    LifeBuoy,
    LogOut,
    Settings,
    UserCog,
    Users,
} from 'lucide-react';
import AnnouncementBanner from '@/components/announcement-banner';
import AppLogo from '@/components/app-logo';
import FlashBanner from '@/components/flash-banner';
import ImpersonationBanner from '@/components/impersonation-banner';
import WorkspaceBanner from '@/components/workspace-banner';
import WorkspaceSwitcher from '@/components/workspace-switcher';
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
} from '@/components/ui/sidebar';
import type { AppLayoutProps, SharedData } from '@/types';

/**
 * The CUSTOMER shell. Staff use admin-layout, because section 3 keeps the two
 * worlds separate right down to the navigation - this sidebar must never link
 * anywhere a customer account cannot go.
 */
export default function AppLayout({ children }: AppLayoutProps) {
    const page = usePage<SharedData>();
    const { sidebarOpen: isOpen, auth, tenancy } = page.props;
    const current = tenancy?.current ?? null;
    const currentUrl = page.url;
    // Set by staff in the console; the link is hidden until there is one.
    const supportUrl = page.props.support?.url ?? null;

    const isMenuActive = (href: string) =>
        currentUrl === href || currentUrl.startsWith(href + '/');

    // Section 3: admins manage people but never billing, and viewers only read.
    const role = tenancy?.available?.find(
        (w) => w.ulid === current?.ulid,
    )?.role;
    const canSeeBilling = role === 'owner' || role === 'billing_manager';

    const nav = [
        {
            href: '/dashboard',
            label: 'Dashboard',
            icon: LayoutDashboard,
            show: true,
        },
        // The example product feature; replace with the real product's screens.
        {
            href: '/projects',
            label: 'Projects',
            icon: FolderKanban,
            show: Boolean(current),
        },
        {
            href: current
                ? `/workspaces/${current.ulid}/members`
                : '/dashboard',
            label: 'Members',
            icon: Users,
            show: Boolean(current),
        },
        {
            href: '/billing',
            label: 'Billing',
            icon: CreditCard,
            show: canSeeBilling,
        },
        {
            href: current
                ? `/workspaces/${current.ulid}/settings`
                : '/dashboard',
            label: 'Settings',
            icon: Settings,
            show: Boolean(current),
        },
    ].filter((item) => item.show);

    return (
        <SidebarProvider defaultOpen={isOpen}>
            <Sidebar collapsible="icon" variant="sidebar">
                <SidebarHeader>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton size="lg" asChild>
                                <Link href="/dashboard" prefetch>
                                    <AppLogo />
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                        <SidebarMenuItem>
                            <WorkspaceSwitcher />
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarHeader>

                <SidebarContent>
                    <SidebarGroup>
                        <SidebarGroupLabel>Menu</SidebarGroupLabel>
                        <SidebarGroupContent>
                            <SidebarMenu>
                                {nav.map((item) => (
                                    <SidebarMenuItem key={item.label}>
                                        <SidebarMenuButton
                                            asChild
                                            isActive={isMenuActive(item.href)}
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
                    <SidebarMenu>
                        {/* A customer with a question about a charge who finds
                            no way to ask us asks their bank instead. */}
                        {supportUrl && (
                            <SidebarMenuItem>
                                <SidebarMenuButton asChild>
                                    <a
                                        href={supportUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <LifeBuoy />
                                        <span>Help &amp; support</span>
                                    </a>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        )}
                        <SidebarMenuItem>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <SidebarMenuButton size="lg">
                                        <Avatar className="size-8">
                                            <AvatarFallback>
                                                {auth.user?.name
                                                    ?.charAt(0)
                                                    ?.toUpperCase() ?? '?'}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="grid flex-1 text-left leading-tight">
                                            <span className="truncate text-sm font-medium">
                                                {auth.user?.name}
                                            </span>
                                            <span className="truncate text-xs text-muted-foreground">
                                                {auth.user?.email}
                                            </span>
                                        </div>
                                    </SidebarMenuButton>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent className="w-56">
                                    <DropdownMenuLabel>
                                        {auth.user?.email}
                                    </DropdownMenuLabel>
                                    <DropdownMenuSeparator />
                                    {/* The person's own account, not the
                                        workspace's (section 2). This was the
                                        only door to it: without it, changing a
                                        password or turning on a second factor
                                        meant knowing the URL. */}
                                    <DropdownMenuItem asChild>
                                        <Link
                                            className="block w-full cursor-pointer"
                                            href="/settings/profile"
                                        >
                                            <UserCog className="mr-2" />
                                            Account settings
                                        </Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem asChild>
                                        <Link
                                            className="block w-full cursor-pointer"
                                            href="/auth/logout"
                                            method="post"
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
                </SidebarFooter>
            </Sidebar>

            <SidebarInset className="overflow-x-hidden">
                <header className="flex shrink-0 items-center gap-2 border-b px-4 py-2.5 sm:px-6 sm:py-3">
                    <SidebarTrigger />
                    <Separator orientation="vertical" className="mx-1 h-5" />
                    <h1 className="text-sm font-semibold tracking-tight sm:text-base">
                        {current?.name ?? page.props.name}
                    </h1>
                </header>

                <div className="flex flex-1 flex-col gap-4 p-3 sm:p-4 md:p-6">
                    <ImpersonationBanner />
                    <FlashBanner />
                    <AnnouncementBanner />
                    <WorkspaceBanner workspace={current} />
                    {children}
                </div>
            </SidebarInset>
        </SidebarProvider>
    );
}
