import { router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useState } from 'react';
import CreateWorkspaceDialog from '@/components/create-workspace-dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenuButton } from '@/components/ui/sidebar';
import type { SharedData } from '@/types';

/**
 * Section 2: "the app always shows a workspace switcher, and what you are
 * allowed to do is decided entirely by which workspace you are currently
 * looking at." The role is shown next to each name for exactly that reason.
 */
export default function WorkspaceSwitcher() {
    const { tenancy } = usePage<SharedData>().props;
    const [creating, setCreating] = useState(false);

    if (!tenancy) return null;

    const current = tenancy.current;
    const available = tenancy.available ?? [];

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <SidebarMenuButton size="lg" className="justify-between">
                        <span className="flex flex-col items-start overflow-hidden">
                            <span className="truncate text-sm font-medium">
                                {current?.name ?? 'No workspace'}
                            </span>
                            {current && (
                                <span className="truncate text-xs text-muted-foreground">
                                    {current.state_label}
                                </span>
                            )}
                        </span>
                        <ChevronsUpDown className="ml-auto size-4 shrink-0" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="start" className="w-64">
                    <DropdownMenuLabel>Workspaces</DropdownMenuLabel>
                    {available.map((item) => (
                        <DropdownMenuItem
                            key={item.ulid}
                            onClick={() =>
                                router.post(`/workspaces/${item.ulid}/switch`)
                            }
                            className="flex items-center justify-between"
                        >
                            <span className="flex flex-col">
                                <span className="text-sm">{item.name}</span>
                                <span className="text-xs text-muted-foreground">
                                    {item.role_label}
                                </span>
                            </span>
                            {current?.ulid === item.ulid && (
                                <Check className="size-4" />
                            )}
                        </DropdownMenuItem>
                    ))}
                    <DropdownMenuSeparator />
                    {/* Opens here rather than linking to the dashboard: this
                        control is on every page, and creating a workspace is
                        an action, not somewhere you go. */}
                    <DropdownMenuItem
                        onSelect={() => setCreating(true)}
                        className="cursor-pointer"
                    >
                        <Plus className="mr-2 size-4" />
                        New workspace
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Sibling of the menu, not a child: the menu unmounts its content
                on close, which would take the dialog with it. */}
            <CreateWorkspaceDialog open={creating} onOpenChange={setCreating} />
        </>
    );
}
