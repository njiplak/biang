import {
    flexRender,
    getCoreRowModel,
    getFilteredRowModel,
    getPaginationRowModel,
    getSortedRowModel,
    useReactTable,
    type ColumnDef,
    type ColumnFiltersState,
    type ColumnPinningState,
    type PaginationState,
    type RowSelectionState,
    type SortingState,
    type VisibilityState,
} from '@tanstack/react-table';
import {
    AlertCircle,
    ArrowDown,
    ArrowUp,
    Download,
    Eye,
    Filter,
    MoreHorizontal,
    PinIcon,
    RefreshCw,
    Search,
    Settings,
    X,
} from 'lucide-react';
import React, {
    useCallback,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { DeleteDialog } from './delete-dialog';
import { TablePagination } from './table-pagination';
import { TableSkeleton } from './table-skeleton';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { Base } from '@/types/base';

type ViewMode = 'table' | 'grid' | 'list';
type ViewDensity = 'compact' | 'normal' | 'comfortable';

type NextTableProps<T> = {
    params?: Record<string, unknown>;
    load: (params: Record<string, unknown>) => Promise<Base<T[]>>;
    id: keyof T;
    onSelect?: (selected: unknown[]) => void;
    onRowClick?: (row: T) => void;
    enableSelect?: boolean;
    columns: ColumnDef<T>[];
    filterComponent?: ReactNode;
    actionComponent?: ReactNode;
    bulkActionComponent?: ReactNode;
    onParamsChange?: (params: Record<string, unknown>) => void;
    mode?: ViewMode;
    gridRenderer?: (row: T, isSelected?: boolean) => ReactNode;
    listRenderer?: (row: T, isSelected?: boolean) => ReactNode;
    enableSearch?: boolean;
    searchPlaceholder?: string;
    enableExport?: boolean;
    onExport?: (data: T[]) => void;
    title?: string;
    description?: string;
    enableModeSwitch?: boolean;
    enableDensityControl?: boolean;
    defaultDensity?: ViewDensity;
    onBulkDelete?: (params: any) => void;
    activeFilterCount?: number;
};

function NextTable<T>({
    params,
    load,
    id,
    onSelect,
    onRowClick,
    enableSelect = false,
    columns,
    filterComponent,
    actionComponent,
    bulkActionComponent,
    onParamsChange,
    mode = 'table',
    gridRenderer,
    listRenderer,
    enableSearch = true,
    searchPlaceholder = 'Search...',
    enableExport = false,
    onExport,
    title,
    description,
    enableDensityControl = true,
    defaultDensity = 'normal',
    onBulkDelete,
    activeFilterCount,
}: NextTableProps<T>) {
    const [data, setData] = useState<Base<T[]>>({});
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [sorting, setSorting] = useState<SortingState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );
    const [pagination, setPagination] = useState<PaginationState>({
        pageIndex: 0,
        pageSize: 10,
    });
    const [customParams, setCustomParams] = useState<Record<string, unknown>>(
        params || {},
    );
    const [columnPinning, setColumnPinning] = useState<ColumnPinningState>({
        left: [],
        right: [],
    });
    const [editedRows, setEditedRows] = useState({});
    const currentMode: ViewMode = mode;
    const [searchQuery, setSearchQuery] = useState('');
    const [density, setDensity] = useState<ViewDensity>(defaultDensity);
    const [showFilters, setShowFilters] = useState(false);
    const [bulkDeleteConfirm, setBulkDeleteConfirm] = useState<boolean>(false);

    const onSelectRef = useRef(onSelect);
    onSelectRef.current = onSelect;

    const select: ColumnDef<T> = {
        id: 'select',
        header: ({ table }) => (
            <div onClick={(e) => e.stopPropagation()}>
                <Checkbox
                    checked={
                        table.getIsAllPageRowsSelected()
                            ? true
                            : table.getIsSomePageRowsSelected()
                              ? 'indeterminate'
                              : false
                    }
                    onCheckedChange={(value) =>
                        table.toggleAllPageRowsSelected(!!value)
                    }
                    aria-label="Select all"
                    className="ml-1"
                />
            </div>
        ),
        cell: ({ row }) => (
            <div onClick={(e) => e.stopPropagation()}>
                <Checkbox
                    checked={row.getIsSelected()}
                    onCheckedChange={(value) => row.toggleSelected(!!value)}
                    aria-label="Select row"
                    className="ml-1"
                />
            </div>
        ),
        enableSorting: false,
        enableHiding: false,
    };

    const tableColumns = enableSelect ? [select, ...columns] : columns;

    const enhanced: ColumnDef<T>[] = tableColumns.map((column) => {
        const header = column.header?.toString() ?? column.id;
        if (column.id == 'select') return column;

        return {
            id: column.id ?? '',
            ...column,
            meta: {
                label: header,
            },
            header: ({ column }) => {
                const isPinned = column.getIsPinned() !== false;
                const canPin = column.columnDef.enablePinning !== false;
                const sorted = column.getIsSorted();

                return (
                    <div className="flex items-center justify-between">
                        <div
                            className="flex cursor-pointer items-center gap-1 select-none"
                            onClick={() =>
                                column.toggleSorting(sorted === 'asc')
                            }
                        >
                            {isPinned && (
                                <PinIcon className="h-3 w-3 text-muted-foreground" />
                            )}
                            <span className="font-medium">{header}</span>
                            {sorted === 'asc' && (
                                <ArrowUp className="h-3 w-3 text-muted-foreground" />
                            )}
                            {sorted === 'desc' && (
                                <ArrowDown className="h-3 w-3 text-muted-foreground" />
                            )}
                        </div>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-8 w-8 p-0"
                                >
                                    <span className="sr-only">Open menu</span>
                                    <MoreHorizontal className="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {column.getCanSort() && (
                                    <>
                                        <DropdownMenuItem
                                            onClick={() =>
                                                column.toggleSorting(false)
                                            }
                                        >
                                            Sort Ascending
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onClick={() =>
                                                column.toggleSorting(true)
                                            }
                                        >
                                            Sort Descending
                                        </DropdownMenuItem>
                                    </>
                                )}
                                {canPin && (
                                    <>
                                        <DropdownMenuSeparator />
                                        {isPinned ? (
                                            <DropdownMenuItem
                                                onClick={() =>
                                                    column.pin(false)
                                                }
                                            >
                                                Unpin Column
                                            </DropdownMenuItem>
                                        ) : (
                                            <>
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        column.pin('left')
                                                    }
                                                >
                                                    Pin to Left
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        column.pin('right')
                                                    }
                                                >
                                                    Pin to Right
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                    </>
                                )}
                                {column.getCanHide() && (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            onClick={() =>
                                                column.toggleVisibility(false)
                                            }
                                        >
                                            Hide Column
                                        </DropdownMenuItem>
                                    </>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                );
            },
        };
    });

    const getFiltersForApi = useCallback(() => {
        const filters = columnFilters.reduce(
            (acc, filter) => {
                acc[filter.id] = filter.value;
                return acc;
            },
            {} as Record<string, unknown>,
        );

        if (enableSearch && searchQuery.trim()) {
            filters['filter[search]'] = searchQuery.trim();
        }

        return filters;
    }, [columnFilters, enableSearch, searchQuery]);

    const getSortingForApi = useCallback(() => {
        const sorts = sorting
            .map((sort) => {
                return sort.desc ? `-${sort.id}` : sort.id;
            })
            .join(',');

        return {
            sort: sorts,
        };
    }, [sorting]);

    useEffect(() => {
        if (onParamsChange) {
            const params = {
                page: pagination.pageIndex + 1,
                per_page: pagination.pageSize,
                ...getFiltersForApi(),
                ...getSortingForApi(),
                ...customParams,
            };
            onParamsChange(params);
        }
    }, [
        pagination,
        getFiltersForApi,
        getSortingForApi,
        customParams,
        onParamsChange,
    ]);

    const fetchData = useCallback(async () => {
        try {
            setLoading(true);
            setError(null);
            const { _refresh: _, ...cleanCustomParams } = customParams;
            const params = {
                page: pagination.pageIndex + 1,
                per_page: pagination.pageSize,
                ...getFiltersForApi(),
                ...getSortingForApi(),
                ...cleanCustomParams,
            };

            const result = await load(params);

            console.log(result);

            setData(result);
            setPagination({
                pageIndex: result.current_page ? result.current_page - 1 : 0,
                pageSize: pagination.pageSize,
            });
            // Clear selection when data is refreshed
            if (enableSelect) {
                setRowSelection({});
            }
        } catch (err) {
            setError(err instanceof Error ? err.message : JSON.stringify(err));
        } finally {
            setLoading(false);
        }
    }, [
        pagination.pageIndex,
        pagination.pageSize,
        getFiltersForApi,
        getSortingForApi,
        customParams,
        load,
        enableSelect,
    ]);

    const prevRefreshRef = useRef<number | undefined>(undefined);
    const fetchDataRef = useRef(fetchData);
    fetchDataRef.current = fetchData;

    useEffect(() => {
        if (params) {
            const { _refresh, ...restParams } = params;
            if (Object.keys(restParams).length > 0) {
                setCustomParams((prev) => ({ ...prev, ...restParams }));
            }
            if (_refresh !== undefined && _refresh !== prevRefreshRef.current) {
                prevRefreshRef.current = _refresh as number;
                fetchDataRef.current();
            }
        }
    }, [params]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    useEffect(() => {
        if (onSelectRef.current && enableSelect) {
            const selectedRows = Object.keys(rowSelection).map((index) => {
                const row = data.items?.[Number.parseInt(index)];
                return row?.[id];
            });
            onSelectRef.current(selectedRows);
        }
    }, [rowSelection, data.items, enableSelect, id]);

    const updateCustomParams = useCallback(
        (newParams: Record<string, unknown>) => {
            setCustomParams((prev) => ({ ...prev, ...newParams }));
        },
        [],
    );

    const handleRefresh = useCallback(() => {
        fetchData();
    }, [fetchData]);

    const handleExport = useCallback(() => {
        if (onExport && data.items) {
            onExport(data.items);
        }
    }, [onExport, data.items]);

    const handleRowClick = useCallback(
        (row: T, event: React.MouseEvent) => {
            const target = event.target as HTMLElement;
            const isInteractiveElement = target.closest(
                'button, input, a, [role="button"], [role="checkbox"]',
            );

            if (isInteractiveElement) {
                return;
            }

            if (onRowClick) {
                onRowClick(row);
            } else if (enableSelect) {
                const index = data.items?.indexOf(row);
                if (index !== undefined && index >= 0) {
                    setRowSelection((prev) => ({
                        ...prev,
                        [index]: !prev[index],
                    }));
                }
            }
        },
        [enableSelect, data.items, onRowClick],
    );

    const table = useReactTable({
        data: data.items || [],
        columns: enhanced,
        pageCount: data.total_page ?? 1,
        state: {
            sorting,
            columnFilters,
            rowSelection,
            pagination,
            columnVisibility,
            columnPinning,
        },
        enableRowSelection: enableSelect,
        onRowSelectionChange: setRowSelection,
        onSortingChange: setSorting,
        onColumnFiltersChange: setColumnFilters,
        onPaginationChange: setPagination,
        onColumnVisibilityChange: setColumnVisibility,
        getCoreRowModel: getCoreRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getPaginationRowModel: getPaginationRowModel(),
        onColumnPinningChange: setColumnPinning,
        manualPagination: true,
        manualSorting: true,
        manualFiltering: true,
        manualExpanding: true,
        manualGrouping: true,
        meta: {
            editedRows,
            setEditedRows,
        },
    });

    // ModeSwitcher removed due to being unused

    const ColumnToggle = () => {
        const hideable = table
            .getAllColumns()
            .filter((column) => column.id != 'select');
        if (hideable.length === 0 || currentMode !== 'table') return null;

        return (
            <Popover>
                <PopoverTrigger asChild>
                    <Button
                        variant="outline"
                        size="sm"
                        className="flex h-8 items-center gap-1 bg-transparent"
                    >
                        <Eye className="h-4 w-4" />
                        <span className="hidden sm:inline">Columns</span>
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-60 p-2" align="end">
                    <div className="space-y-2">
                        <h4 className="mb-2 font-medium">Toggle Columns</h4>
                        <div className="space-y-2">
                            {table.getAllColumns().map((column) => {
                                return (
                                    <div
                                        key={column.id}
                                        className="flex items-center space-x-2"
                                    >
                                        <Checkbox
                                            id={`column-${column.id}`}
                                            disabled={!column.getCanHide()}
                                            checked={column.getIsVisible()}
                                            onCheckedChange={(value) =>
                                                column.toggleVisibility(!!value)
                                            }
                                        />
                                        <label
                                            htmlFor={`column-${column.id}`}
                                            className="text-sm leading-none font-medium peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                                        >
                                            {
                                                (
                                                    column.columnDef.meta as {
                                                        label?: string;
                                                    }
                                                )?.label
                                            }
                                        </label>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </PopoverContent>
            </Popover>
        );
    };

    const SettingsDropdown = () => {
        return (
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-8 w-8 bg-transparent p-0"
                    >
                        <Settings className="h-4 w-4" />
                        <span className="sr-only">Settings</span>
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-48">
                    {enableDensityControl && (
                        <>
                            <DropdownMenuLabel>View Density</DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                                value={density}
                                onValueChange={(value) =>
                                    setDensity(value as ViewDensity)
                                }
                            >
                                <DropdownMenuRadioItem value="compact">
                                    Compact
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="normal">
                                    Normal
                                </DropdownMenuRadioItem>
                                <DropdownMenuRadioItem value="comfortable">
                                    Comfortable
                                </DropdownMenuRadioItem>
                            </DropdownMenuRadioGroup>
                        </>
                    )}
                    {enableExport && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                onClick={handleExport}
                                disabled={!data.items?.length}
                            >
                                <Download className="mr-2 h-4 w-4" />
                                Export Data
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        );
    };

    const getDensityClasses = () => {
        switch (density) {
            case 'compact':
                return 'text-xs';
            case 'comfortable':
                return 'text-base py-4';
            default:
                return 'text-sm py-2';
        }
    };

    const ErrorDisplay = () => {
        if (!error) return null;

        return (
            <Alert variant="destructive" className="mb-4">
                <AlertCircle className="h-4 w-4" />
                <AlertDescription className="flex items-center justify-between">
                    <span>Error: {error}</span>
                    <Button variant="outline" size="sm" onClick={handleRefresh}>
                        <RefreshCw className="mr-2 h-4 w-4" />
                        Retry
                    </Button>
                </AlertDescription>
            </Alert>
        );
    };

    const EmptyState = () => (
        <div className="flex flex-col items-center justify-center px-4 py-12 text-center">
            <div className="mb-4 rounded-full bg-muted p-4">
                <Search className="h-8 w-8 text-muted-foreground" />
            </div>
            <h3 className="mb-2 text-lg font-semibold">No results found</h3>
            <p className="mb-4 max-w-sm text-muted-foreground">
                {searchQuery || columnFilters.length > 0
                    ? "Try adjusting your search or filters to find what you're looking for."
                    : 'There are no items to display at the moment.'}
            </p>
            {(searchQuery || columnFilters.length > 0) && (
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                        setSearchQuery('');
                        setColumnFilters([]);
                    }}
                >
                    Clear filters
                </Button>
            )}
        </div>
    );

    return (
        <div className="w-full space-y-6">
            {(title || description) && (
                <div className="space-y-2">
                    {title && (
                        <h2 className="text-2xl font-bold tracking-tight text-foreground">
                            {title}
                        </h2>
                    )}
                    {description && (
                        <p className="leading-relaxed text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
            )}

            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex flex-1 items-center gap-3">
                        {enableSearch && (
                            <div className="relative max-w-sm flex-1">
                                <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    placeholder={searchPlaceholder}
                                    value={searchQuery}
                                    onChange={(e) =>
                                        setSearchQuery(e.target.value)
                                    }
                                    className="h-9 pl-10"
                                />
                            </div>
                        )}
                        {filterComponent && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setShowFilters(!showFilters)}
                                className="h-9 px-3 text-xs font-medium"
                            >
                                <Filter className="mr-2 h-4 w-4" />
                                <span className="hidden sm:inline">
                                    Filters
                                </span>
                                {(activeFilterCount ?? 0) > 0 && (
                                    <Badge
                                        variant="destructive"
                                        className="ml-2 h-5 overflow-hidden px-1.5 text-xs"
                                    >
                                        {activeFilterCount ?? 0}
                                    </Badge>
                                )}
                            </Button>
                        )}
                    </div>

                    <div className="flex items-center gap-2">
                        {React.isValidElement(actionComponent) &&
                            actionComponent}
                        {/* <ModeSwitcher /> */}
                        <ColumnToggle />
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={handleRefresh}
                            disabled={loading}
                            className="h-8 w-8 bg-transparent p-0"
                        >
                            <RefreshCw
                                className={cn(
                                    'h-4 w-4',
                                    loading && 'animate-spin',
                                )}
                            />
                            <span className="sr-only">Refresh</span>
                        </Button>
                        <SettingsDropdown />
                    </div>
                </div>

                {enableSelect && Object.keys(rowSelection).length > 0 && (
                    <div className="flex flex-col gap-2 rounded-lg border bg-muted/50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-2">
                            <Badge variant="secondary" className="font-medium">
                                {Object.keys(rowSelection).length}
                            </Badge>
                            <span className="text-sm text-muted-foreground">
                                <span className="hidden sm:inline">of {data.items?.length || 0} row(s) </span>selected
                            </span>
                        </div>
                        <div className="flex space-x-2">
                            {bulkActionComponent && bulkActionComponent}
                            {onBulkDelete && (
                                <>
                                    <DeleteDialog
                                        id={bulkDeleteConfirm}
                                        onDelete={onBulkDelete}
                                        onOpenChange={setBulkDeleteConfirm}
                                    />
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        onClick={(e) => {
                                            e.preventDefault();
                                            setBulkDeleteConfirm(true);
                                        }}
                                        className="h-8 px-3 text-xs"
                                    >
                                        Delete
                                    </Button>
                                </>
                            )}
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setRowSelection({})}
                                className="h-8 px-3 text-xs"
                            >
                                Clear
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            {showFilters && filterComponent && (
                <div className="rounded-lg border bg-card p-6">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-base font-semibold">Filters</h3>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setShowFilters(false)}
                            className="h-8 w-8 p-0"
                        >
                            <X className="h-4 w-4" />
                            <span className="sr-only">Close filters</span>
                        </Button>
                    </div>
                    <Separator className="mb-4" />
                    {React.isValidElement(filterComponent)
                        ? React.cloneElement(
                              filterComponent as React.ReactElement<
                                  Record<string, unknown>
                              >,
                              {
                                  updateParams: updateCustomParams,
                                  currentParams: customParams,
                              },
                          )
                        : filterComponent}
                </div>
            )}

            <ErrorDisplay />

            <div className="rounded-lg border bg-card">
                {loading && (
                    <div className="p-6">
                        {currentMode === 'table' && (
                            <TableSkeleton
                                columnCount={columns.length}
                                rowCount={10}
                            />
                        )}
                        {currentMode === 'grid' && (
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {Array.from({
                                    length: pagination.pageSize,
                                }).map((_, i) => (
                                    <div
                                        key={i}
                                        className="h-48 animate-pulse rounded-lg bg-muted"
                                    />
                                ))}
                            </div>
                        )}
                        {currentMode === 'list' && (
                            <div className="space-y-3">
                                {Array.from({
                                    length: pagination.pageSize,
                                }).map((_, i) => (
                                    <div
                                        key={i}
                                        className="h-16 animate-pulse rounded-lg bg-muted"
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {!loading && currentMode === 'table' && (
                    <div className="overflow-hidden">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    {table
                                        .getHeaderGroups()
                                        .map((headerGroup) => (
                                            <TableRow
                                                key={headerGroup.id}
                                                className="group"
                                            >
                                                {headerGroup.headers.map(
                                                    (header) => {
                                                        const isPinned =
                                                            header.column.getIsPinned();
                                                        const pinStyle =
                                                            isPinned
                                                                ? ({
                                                                      position:
                                                                          'sticky',
                                                                      left:
                                                                          isPinned ===
                                                                          'left'
                                                                              ? 0
                                                                              : undefined,
                                                                      right:
                                                                          isPinned ===
                                                                          'right'
                                                                              ? 0
                                                                              : undefined,
                                                                      backgroundColor:
                                                                          'hsl(var(--card))',
                                                                      zIndex: 10,
                                                                  } satisfies React.CSSProperties)
                                                                : {};

                                                        return (
                                                            <TableHead
                                                                key={header.id}
                                                                colSpan={
                                                                    header.colSpan
                                                                }
                                                                style={
                                                                    isPinned
                                                                        ? pinStyle
                                                                        : {}
                                                                }
                                                                className={cn(
                                                                    'px-4 py-3 text-left font-medium',
                                                                    isPinned &&
                                                                        'shadow-[2px_0_5px_-2px_rgba(0,0,0,0.1)] dark:shadow-[2px_0_5px_-2px_rgba(255,255,255,0.1)]',
                                                                    getDensityClasses(),
                                                                )}
                                                            >
                                                                {header.isPlaceholder
                                                                    ? null
                                                                    : flexRender(
                                                                          header
                                                                              .column
                                                                              .columnDef
                                                                              .header,
                                                                          header.getContext(),
                                                                      )}
                                                            </TableHead>
                                                        );
                                                    },
                                                )}
                                            </TableRow>
                                        ))}
                                </TableHeader>
                                <TableBody>
                                    {table.getRowModel().rows?.length ? (
                                        table.getRowModel().rows.map((row) => (
                                            <TableRow
                                                key={row.id}
                                                data-state={
                                                    row.getIsSelected()
                                                        ? 'selected'
                                                        : undefined
                                                }
                                                className={cn(
                                                    'cursor-pointer transition-colors hover:bg-muted/50',
                                                    row.getIsSelected() &&
                                                        'bg-muted/50',
                                                )}
                                                onClick={(e) =>
                                                    handleRowClick(
                                                        row.original,
                                                        e,
                                                    )
                                                }
                                            >
                                                {row
                                                    .getVisibleCells()
                                                    .map((cell) => {
                                                        const isPinned =
                                                            cell.column.getIsPinned();
                                                        const pinStyle =
                                                            isPinned
                                                                ? ({
                                                                      position:
                                                                          'sticky',
                                                                      left:
                                                                          isPinned ===
                                                                          'left'
                                                                              ? 0
                                                                              : undefined,
                                                                      right:
                                                                          isPinned ===
                                                                          'right'
                                                                              ? 0
                                                                              : undefined,
                                                                      backgroundColor:
                                                                          'hsl(var(--card))',
                                                                      zIndex: 1,
                                                                  } satisfies React.CSSProperties)
                                                                : {};

                                                        return (
                                                            <TableCell
                                                                key={cell.id}
                                                                style={
                                                                    isPinned
                                                                        ? pinStyle
                                                                        : undefined
                                                                }
                                                                className={cn(
                                                                    'px-4',
                                                                    isPinned &&
                                                                        'shadow-[2px_0_5px_-2px_rgba(0,0,0,0.1)] dark:shadow-[2px_0_5px_-2px_rgba(255,255,255,0.1)]',
                                                                    getDensityClasses(),
                                                                )}
                                                            >
                                                                {flexRender(
                                                                    cell.column
                                                                        .columnDef
                                                                        .cell,
                                                                    cell.getContext(),
                                                                )}
                                                            </TableCell>
                                                        );
                                                    })}
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={
                                                    table.getAllColumns().length
                                                }
                                                className="p-0"
                                            >
                                                <EmptyState />
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                )}

                {!loading && currentMode === 'grid' && (
                    <div className="p-6">
                        {data.items && data.items.length > 0 ? (
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {data.items.map((row, index) => (
                                    <div
                                        key={`grid-item-${index}`}
                                        className={cn(
                                            'cursor-pointer overflow-hidden rounded-lg border bg-card transition-all hover:shadow-md',
                                            enableSelect &&
                                                rowSelection[index] &&
                                                'shadow-md ring-2 ring-primary',
                                        )}
                                        onClick={(e) => handleRowClick(row, e)}
                                    >
                                        {gridRenderer ? (
                                            gridRenderer(
                                                row,
                                                enableSelect
                                                    ? !!rowSelection[index]
                                                    : false,
                                            )
                                        ) : (
                                            <div className="p-6">
                                                <p className="text-sm text-muted-foreground">
                                                    No grid renderer provided
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <EmptyState />
                        )}
                    </div>
                )}

                {!loading && currentMode === 'list' && (
                    <div className="p-6">
                        {data.items && data.items.length > 0 ? (
                            <div className="space-y-3">
                                {data.items.map((row, index) => (
                                    <div
                                        key={`list-item-${index}`}
                                        className={cn(
                                            'cursor-pointer overflow-hidden rounded-lg border bg-card transition-all hover:shadow-sm',
                                            enableSelect &&
                                                rowSelection[index] &&
                                                'shadow-sm ring-2 ring-primary',
                                            getDensityClasses(),
                                        )}
                                        onClick={(e) => handleRowClick(row, e)}
                                    >
                                        {listRenderer ? (
                                            listRenderer(
                                                row,
                                                enableSelect
                                                    ? !!rowSelection[index]
                                                    : false,
                                            )
                                        ) : (
                                            <div className="p-4">
                                                <p className="text-sm text-muted-foreground">
                                                    No list renderer provided
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <EmptyState />
                        )}
                    </div>
                )}
            </div>

            <TablePagination table={table} />
        </div>
    );
}

export default NextTable;
