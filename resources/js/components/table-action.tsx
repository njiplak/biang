import { Link } from '@inertiajs/react';
import { Eye, Trash } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { DeleteDialog } from './delete-dialog';

type TableActionProps = {
    id: number | string;
    showUrl: string;
    onDelete: (id: number | string) => Promise<void> | void;
    children?: React.ReactNode; // ✅ tambahkan ini
};

export function TableAction({ id, showUrl, onDelete, children }: TableActionProps) {
    const [deleteId, setDeleteId] = useState<number | string | null>(null);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline" size="sm">
                        Action
                    </Button>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="center">
                    {/* Detail */}
                    <Link href={showUrl} method="get">
                        <DropdownMenuItem>
                            <Eye className="mr-2 h-4 w-4" /> Detail
                        </DropdownMenuItem>
                    </Link>

                    {/* custom action */}
                    {children}

                    {/* Delete */}
                    <DropdownMenuItem
                        className="text-red-500 hover:text-red-500"
                        onClick={(e) => {
                            e.preventDefault();
                            setDeleteId(id);
                        }}
                    >
                        <Trash className="mr-2 h-4 w-4 text-red-500" />
                        <span className="text-red-500">Delete</span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Delete Dialog */}
            <DeleteDialog
                id={deleteId}
                onDelete={onDelete}
                onOpenChange={(val) => {
                    if (!val) setDeleteId(null);
                }}
            />
        </>
    );
}
