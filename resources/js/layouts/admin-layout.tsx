import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    ChevronsUpDown,
    FileText,
    LayoutDashboard,
    LogOut,
    Megaphone,
    Settings,
    ScrollText,
    ShieldCheck,
    Tags,
    Timer,
    Users,
    Wallet,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
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
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isMenuActive(
                                            admin.dashboard.url(),
                                            true,
                                        )}
                                    >
                                        <Link href={admin.dashboard.url()}>
                                            <LayoutDashboard />
                                            <span>Dashboard</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isMenuActive(
                                            admin.customer.index.url(),
                                        )}
                                    >
                                        <Link href={admin.customer.index.url()}>
                                            <Building2 />
                                            <span>Customers</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isMenuActive(
                                            admin.catalog.index.url(),
                                        )}
                                    >
                                        <Link href={admin.catalog.index.url()}>
                                            <Tags />
                                            <span>Plans</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isMenuActive(
                                            admin.announcement.index.url(),
                                        )}
                                    >
                                        <Link
                                            href={admin.announcement.index.url()}
                                        >
                                            <Megaphone />
                                            <span>Announcements</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isMenuActive(
                                            admin.page.index.url(),
                                        )}
                                    >
                                        <Link href={admin.page.index.url()}>
                                            <FileText />
                                            <span>Pages</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isMenuActive(
                                            admin.billingOps.index.url(),
                                        )}
                                    >
                                        <Link
                                            href={admin.billingOps.index.url()}
                                        >
                                            <Wallet />
                                            <span>Billing ops</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isMenuActive(
                                            admin.staff.index.url(),
                                        )}
                                    >
                                        <Link href={admin.staff.index.url()}>
                                            <ShieldCheck />
                                            <span>Staff</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isMenuActive(
                                            admin.audit.index.url(),
                                        )}
                                    >
                                        <Link href={admin.audit.index.url()}>
                                            <ScrollText />
                                            <span>Audit trail</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isMenuActive(
                                            admin.scheduler.index.url(),
                                        )}
                                    >
                                        <Link
                                            href={admin.scheduler.index.url()}
                                        >
                                            <Timer />
                                            <span>Scheduled tasks</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isMenuActive(
                                            admin.setting.user.index.url(),
                                        )}
                                    >
                                        <Link
                                            href={admin.setting.user.index.url()}
                                        >
                                            <Users />
                                            <span>Users</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={isMenuActive(
                                            admin.setting.setting.index.url(),
                                        )}
                                    >
                                        <Link
                                            href={admin.setting.setting.index.url()}
                                        >
                                            <Settings />
                                            <span>Settings</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
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
                    {children}
                </div>
            </SidebarInset>
        </SidebarProvider>
    );
}
