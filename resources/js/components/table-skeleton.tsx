'use client';

import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

interface TableSkeletonProps {
    columnCount: number;
    rowCount?: number;
    showHeader?: boolean;
}

export function TableSkeleton({
    columnCount,
    rowCount = 10,
    showHeader = true,
}: TableSkeletonProps) {
    return (
        <div className="rounded-md border">
            <Table>
                {showHeader && (
                    <TableHeader>
                        <TableRow>
                            {Array.from({ length: columnCount }).map(
                                (_, index) => (
                                    <TableHead key={index}>
                                        <Skeleton className="h-4 w-[100px]" />
                                    </TableHead>
                                ),
                            )}
                        </TableRow>
                    </TableHeader>
                )}
                <TableBody>
                    {Array.from({ length: rowCount }).map((_, rowIndex) => (
                        <TableRow key={rowIndex}>
                            {Array.from({ length: columnCount }).map(
                                (_, cellIndex) => (
                                    <TableCell key={cellIndex}>
                                        <Skeleton className="h-4 w-[80px]" />
                                    </TableCell>
                                ),
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
