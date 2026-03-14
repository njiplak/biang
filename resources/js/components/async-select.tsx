import { Check, ChevronDown, Loader2, X } from 'lucide-react';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { useDebounce } from '@/hooks/use-debounce';

// Conditional types based on mode
type AsyncSelectValue<TValue, TMode extends 'single' | 'multiple'> = TMode extends 'multiple' ? TValue[] : TValue;

type AsyncSelectOnChange<T, TValue, TMode extends 'single' | 'multiple'> = TMode extends 'multiple'
    ? (values: TValue[], options: T[]) => void
    : (value: TValue | null, option: T) => void;

type AsyncSelectInitialOptions<T, TMode extends 'single' | 'multiple'> = TMode extends 'multiple' ? T[] : T | undefined;

type AsyncSelectProps<T, TValue extends string | number = string | number, TMode extends 'single' | 'multiple' = 'single'> = {
    // Core functionality
    fetcher: (query: string) => Promise<T[]>;
    getOptionValue: (option: T) => TValue;
    getDisplayValue: (option: T) => string;
    mode?: TMode;
    value?: AsyncSelectValue<TValue, TMode>;
    defaultValue?: AsyncSelectValue<TValue, TMode>;
    onChange: AsyncSelectOnChange<T, TValue, TMode>;
    // Customization
    renderOption?: (option: T) => React.ReactNode;
    renderSelectedItem?: (option: T, onRemove: () => void) => React.ReactNode; // For custom selected item rendering
    label?: string;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyMessage?: string;
    loadingMessage?: string;
    // State
    disabled?: boolean;
    clearable?: boolean;
    preload?: boolean;
    initialOptions?: AsyncSelectInitialOptions<T, TMode>; // Updated to depend on mode
    // Styling
    className?: string;
    maxSelectedDisplay?: number; // Max number of selected items to show before "X more"
    // Behavior
    debounceMs?: number;
    minSearchLength?: number;
    maxResults?: number;
    maxSelections?: number; // Max number of items that can be selected
    cacheResults?: boolean;
    fetchSelectedValue?: boolean;
    errorMessage?: string;
};

export function AsyncSelect<T, TValue extends string | number = string | number, TMode extends 'single' | 'multiple' = 'single'>({
    fetcher,
    getOptionValue,
    getDisplayValue,
    mode = 'single' as TMode,
    value,
    defaultValue,
    onChange,
    renderOption,
    label,
    placeholder = 'Select...',
    searchPlaceholder = 'Search...',
    emptyMessage = 'No results found.',
    loadingMessage = 'Loading...',
    disabled = false,
    clearable = false,
    preload = true,
    initialOptions,
    className,
    maxSelectedDisplay = 3,
    debounceMs = 300,
    minSearchLength = 0,
    maxResults = 100,
    maxSelections,
    cacheResults = true,
    fetchSelectedValue = true,
    errorMessage,
}: AsyncSelectProps<T, TValue, TMode>) {
    const [open, setOpen] = React.useState(false);
    const [options, setOptions] = React.useState<T[]>([]);
    const [search, setSearch] = React.useState('');
    const [loading, setLoading] = React.useState(false);
    const [error, setError] = React.useState<string | null>(null);
    const [hasSearched, setHasSearched] = React.useState(false);

    // Initialize selectedOptionsCache based on mode and initialOptions
    const [selectedOptionsCache, setSelectedOptionsCache] = React.useState<T[]>(() => {
        if (mode === 'multiple') {
            return (initialOptions as T[]) || [];
        } else {
            return initialOptions ? [initialOptions as T] : [];
        }
    });

    // Use value or defaultValue, handle both single and multiple modes
    const currentValue = value ?? defaultValue;

    const currentValues = React.useMemo((): TValue[] => {
        if (mode === 'multiple') {
            return (currentValue as TValue[]) || [];
        }
        return currentValue !== undefined && currentValue !== null ? [currentValue as TValue] : [];
    }, [currentValue, mode]);

    // Cache for results to improve UX
    const cacheRef = React.useRef<Map<string, T[]>>(new Map());

    // Track if component is mounted to prevent state updates after unmount
    const mountedRef = React.useRef(true);
    React.useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
        };
    }, []);

    // Add effect to handle initial options
    React.useEffect(() => {
        if (initialOptions) {
            const optionsArray = mode === 'multiple' ? (initialOptions as T[]) : [initialOptions as T];
            if (optionsArray.length > 0) {
                setSelectedOptionsCache(optionsArray);
                // Add to options if not already there
                setOptions((prev) => {
                    const existingValues = prev.map(getOptionValue);
                    const newOptions = optionsArray.filter((opt) => !existingValues.includes(getOptionValue(opt)));
                    return [...newOptions, ...prev];
                });
            }
        }
    }, [initialOptions, getOptionValue, mode]);

    const fetchSpecificValues = React.useCallback(
        async (valuesToFetch: TValue[]) => {
            if (!fetchSelectedValue || valuesToFetch.length === 0) return;

            try {
                setLoading(true);
                // Try to fetch with each value as query to see if we can find them
                const allResults: T[] = [];
                for (const valueToFetch of valuesToFetch) {
                    try {
                        const results = await fetcher(String(valueToFetch));
                        const matchingOption = results.find((option) => String(getOptionValue(option)) === String(valueToFetch));
                        if (matchingOption) {
                            allResults.push(matchingOption);
                        }
                    } catch (err) {
                        console.error(`Failed to fetch value ${valueToFetch}:`, err);
                    }
                }

                if (allResults.length > 0 && mountedRef.current) {
                    setSelectedOptionsCache((prev) => {
                        const existingValues = prev.map((opt) => String(getOptionValue(opt)));
                        const newOptions = allResults.filter((opt) => !existingValues.includes(String(getOptionValue(opt))));
                        return [...prev, ...newOptions];
                    });

                    // Add to options if not already there
                    setOptions((prev) => {
                        const existingValues = prev.map((opt) => String(getOptionValue(opt)));
                        const newOptions = allResults.filter((opt) => !existingValues.includes(String(getOptionValue(opt))));
                        return [...newOptions, ...prev];
                    });
                }
            } catch (err) {
                console.error('Failed to fetch selected values:', err);
            } finally {
                if (mountedRef.current) {
                    setLoading(false);
                }
            }
        },
        [fetcher, getOptionValue, fetchSelectedValue],
    );

    // Effect to fetch selected values if they're not in current options
    React.useEffect(() => {
        if (currentValues.length > 0 && fetchSelectedValue) {
            const missingValues = currentValues.filter((val) => {
                const existsInCache = selectedOptionsCache.some((opt) => String(getOptionValue(opt)) === String(val));
                const existsInOptions = options.some((opt) => String(getOptionValue(opt)) === String(val));
                return !existsInCache && !existsInOptions;
            });

            if (missingValues.length > 0) {
                fetchSpecificValues(missingValues);
            }
        }
    }, [currentValues, selectedOptionsCache, options, getOptionValue, fetchSpecificValues, fetchSelectedValue]);

    const fetchData = React.useCallback(
        async (query: string, immediate = false) => {
            // Skip fetch if query is too short and not immediate
            if (!immediate && query.length > 0 && query.length < minSearchLength) {
                return;
            }

            // Check cache first
            if (cacheResults && cacheRef.current.has(query)) {
                const cachedResults = cacheRef.current.get(query)!;
                if (mountedRef.current) {
                    setOptions(cachedResults);
                    setError(null);
                }
                return;
            }

            if (mountedRef.current) {
                setLoading(true);
                setError(null);
                setHasSearched(true);
            }

            try {
                const results = (await fetcher(query)) ?? [];
                const limitedResults = maxResults ? results.slice(0, maxResults) : results;

                if (mountedRef.current) {
                    setOptions(limitedResults || []);
                    // Cache results
                    if (cacheResults) {
                        cacheRef.current.set(query, limitedResults || []);
                    }
                }
            } catch (err) {
                console.error('Fetch failed:', err);
                if (mountedRef.current) {
                    const fetchError = err instanceof Error ? err.message : 'Failed to fetch data';
                    setError(fetchError);
                    setOptions([]);
                }
            } finally {
                if (mountedRef.current) {
                    setLoading(false);
                }
            }
        },
        [fetcher, minSearchLength, maxResults, cacheResults],
    );

    // Debounced search with cleanup
    const [debouncedFetch, cancelFetch] = useDebounce(fetchData, debounceMs);

    // Initial fetch - only if preload is enabled
    React.useEffect(() => {
        if (preload) {
            fetchData('', true);
        }
    }, [fetchData, preload]);

    const handleSearch = (query: string) => {
        setSearch(query);
        // Cancel previous debounced call
        cancelFetch();
        // If query is empty or meets minimum length, fetch immediately
        if (query === '' || query.length >= minSearchLength) {
            debouncedFetch(query);
        } else {
            // Show loading state for better UX even when not fetching
            setLoading(false);
            setOptions([]);
        }
    };

    const handleSelect = (selectedValue: string) => {
        const selectedOption = options.find((option) => String(getOptionValue(option)) === selectedValue);
        if (!selectedOption) return;

        const optionValue = getOptionValue(selectedOption);

        if (mode === 'multiple') {
            // Use the actual value prop, not currentValue
            const actualCurrentValues = (value as TValue[]) || (defaultValue as TValue[]) || [];
            // Check if already selected - need to compare properly
            const isAlreadySelected = actualCurrentValues.some((val) => String(val) === selectedValue);

            if (isAlreadySelected) {
                // Remove from selection
                const newValues = actualCurrentValues.filter((val) => String(val) !== selectedValue);
                const newOptions = selectedOptionsCache.filter((opt) => String(getOptionValue(opt)) !== selectedValue);
                setSelectedOptionsCache(newOptions);
                (onChange as AsyncSelectOnChange<T, TValue, 'multiple'>)(newValues, newOptions);
            } else {
                // Check max selections limit
                if (maxSelections && actualCurrentValues.length >= maxSelections) {
                    return;
                }
                // Add to selection
                const newValues = [...actualCurrentValues, optionValue];
                const newOptions = [...selectedOptionsCache, selectedOption];
                setSelectedOptionsCache(newOptions);
                (onChange as AsyncSelectOnChange<T, TValue, 'multiple'>)(newValues, newOptions);
            }
            // Don't close popover in multiple mode
            setSearch(''); // Clear search after selection
        } else {
            // Single mode
            setSelectedOptionsCache([selectedOption]);
            (onChange as AsyncSelectOnChange<T, TValue, 'single'>)(optionValue, selectedOption);
            setOpen(false);
            setSearch(''); // Clear search after selection
        }
    };

    const handleRemoveItem = React.useCallback(
        (valueToRemove: TValue) => {
            if (mode === 'multiple') {
                // Use the actual value prop, not currentValue which might be stale
                const actualCurrentValues = (value as TValue[]) || (defaultValue as TValue[]) || [];
                const newValues = actualCurrentValues.filter((val) => String(val) !== String(valueToRemove));
                const newOptions = selectedOptionsCache.filter((opt) => String(getOptionValue(opt)) !== String(valueToRemove));
                setSelectedOptionsCache(newOptions);
                (onChange as AsyncSelectOnChange<T, TValue, 'multiple'>)(newValues, newOptions);
            }
        },
        [mode, value, defaultValue, selectedOptionsCache, getOptionValue, onChange],
    );

    const handleClear = (e: React.MouseEvent) => {
        e.stopPropagation();
        setSelectedOptionsCache([]);
        if (mode === 'multiple') {
            (onChange as AsyncSelectOnChange<T, TValue, 'multiple'>)([], []);
        } else {
            (onChange as AsyncSelectOnChange<T, TValue, 'single'>)(null, {} as T);
        }
    };

    const handleOpenChange = (newOpen: boolean) => {
        setOpen(newOpen);
        if (!newOpen) {
            setSearch('');
            cancelFetch(); // Cancel any pending fetch when closing
        }
    };

    const selectedOptions = React.useMemo(() => {
        return currentValues
            .map((val) => {
                // First try to find in current options
                const optionInList = options.find((option) => String(getOptionValue(option)) === String(val));
                if (optionInList) return optionInList;

                // Then try cached selected options
                const cachedOption = selectedOptionsCache.find((option) => String(getOptionValue(option)) === String(val));
                return cachedOption ?? null;
            })
            .filter((opt) => opt !== null);
    }, [currentValues, options, selectedOptionsCache, getOptionValue]);

    const renderTriggerContent = () => {
        if (mode === 'multiple') {
            const selectedCount = selectedOptions.length;
            if (selectedCount === 0) {
                return <span className="text-muted-foreground">{placeholder}</span>;
            }

            if (selectedCount <= maxSelectedDisplay) {
                return (
                    <div className="flex max-w-full flex-wrap gap-1">
                        {selectedOptions.map((option) => {
                            const optionValue = getOptionValue(option);
                            return (
                                <Badge key={optionValue} variant="secondary" className="flex items-center gap-1 text-xs">
                                    <span className="truncate">{getDisplayValue(option)}</span>
                                    {!disabled && (
                                        <button
                                            type="button"
                                            className="ml-1 rounded-full ring-offset-background transition-colors outline-none hover:bg-destructive hover:text-destructive-foreground focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                e.stopPropagation();
                                                handleRemoveItem(optionValue);
                                            }}
                                        >
                                            <X className="h-3 w-3" />
                                            <span className="sr-only">Remove {getDisplayValue(option)}</span>
                                        </button>
                                    )}
                                </Badge>
                            );
                        })}
                    </div>
                );
            }

            return (
                <div className="flex items-center gap-2">
                    <div className="flex gap-1">
                        {selectedOptions.slice(0, maxSelectedDisplay).map((option) => {
                            const optionValue = getOptionValue(option);
                            return (
                                <Badge key={optionValue} variant="secondary" className="flex items-center gap-1 text-xs">
                                    <span className="truncate">{getDisplayValue(option)}</span>
                                    {!disabled && (
                                        <button
                                            type="button"
                                            className="ml-1 rounded-full ring-offset-background transition-colors outline-none hover:bg-destructive hover:text-destructive-foreground focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                e.stopPropagation();
                                                handleRemoveItem(optionValue);
                                            }}
                                        >
                                            <X className="h-3 w-3" />
                                            <span className="sr-only">Remove {getDisplayValue(option)}</span>
                                        </button>
                                    )}
                                </Badge>
                            );
                        })}
                    </div>
                    <span className="text-sm text-muted-foreground">+{selectedCount - maxSelectedDisplay} more</span>
                </div>
            );
        }

        // Single mode
        const selectedOption = selectedOptions[0];
        if (selectedOption) return getDisplayValue(selectedOption);
        // Show placeholder or loading indicator while value is being resolved
        if (currentValues.length > 0 && loading) {
            return <span className="text-muted-foreground">Loading...</span>;
        }
        return <span className="text-muted-foreground">{placeholder}</span>;
    };

    const renderOptionContent = (option: T) => {
        if (renderOption) {
            return renderOption(option);
        }

        const text = getDisplayValue(option);
        // Highlight search term in results
        if (search && text.toLowerCase().includes(search.toLowerCase())) {
            const escapedSearch = search.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const parts = text.split(new RegExp(`(${escapedSearch})`, 'gi'));
            return (
                <span>
                    {parts.map((part, index) =>
                        part.toLowerCase() === search.toLowerCase() ? (
                            <mark key={index} className="rounded bg-yellow-200 px-0.5 dark:bg-yellow-800">
                                {part}
                            </mark>
                        ) : (
                            part
                        ),
                    )}
                </span>
            );
        }
        return text;
    };

    const showEmpty = !loading && options.length === 0 && hasSearched;
    const showMinLength = search.length > 0 && search.length < minSearchLength;
    const hasValue = selectedOptions.length > 0 || currentValues.length > 0;

    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            {label && <Label className={cn(disabled && 'text-muted-foreground', errorMessage && 'text-destructive')}>{label}</Label>}
            <Popover open={open} onOpenChange={handleOpenChange}>
                <PopoverTrigger asChild>
                    <Button
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-label={label}
                        disabled={disabled}
                        className={cn(
                            'min-h-10 w-full justify-between text-left font-normal bg-background',
                            !hasValue && 'text-muted-foreground',
                            'focus:ring-2 focus:ring-ring focus:ring-offset-2',
                            mode === 'multiple' && hasValue && 'h-auto py-2',
                        )}
                    >
                        <div className="flex-1 truncate">{renderTriggerContent()}</div>
                        <div className="ml-2 flex items-center gap-1">
                            {clearable && hasValue && !disabled && (
                                <button
                                    type="button"
                                    className="rounded-full ring-offset-background outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                    onClick={handleClear}
                                >
                                    <X className="h-4 w-4 opacity-50 transition-opacity hover:opacity-100" />
                                    <span className="sr-only">Clear selection</span>
                                </button>
                            )}
                            <ChevronDown className={cn('h-4 w-4 opacity-50 transition-transform duration-200', open && 'rotate-180')} />
                        </div>
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[--radix-popover-trigger-width] min-w-[200px] p-0" align="start" sideOffset={4}>
                    <Command shouldFilter={false}>
                        <CommandInput
                            placeholder={searchPlaceholder}
                            value={search}
                            onValueChange={handleSearch}
                            disabled={disabled}
                            className="h-11"
                        />
                        <CommandList className="max-h-[300px]">
                            {loading && (
                                <div className="flex items-center justify-center py-8">
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    <span className="text-sm text-muted-foreground">{loadingMessage}</span>
                                </div>
                            )}
                            {showMinLength && (
                                <div className="flex items-center justify-center py-8">
                                    <span className="text-sm text-muted-foreground">Type at least {minSearchLength} characters to search</span>
                                </div>
                            )}
                            {showEmpty && (
                                <CommandEmpty>
                                    {error ? (
                                        <div className="py-8 text-center">
                                            <p className="mb-2 text-sm text-destructive">⚠️ Error</p>
                                            <p className="mb-4 text-xs text-muted-foreground">{error}</p>
                                            <Button variant="outline" size="sm" onClick={() => fetchData(search)}>
                                                Try Again
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="py-8 text-center">
                                            <p className="text-sm text-muted-foreground">{emptyMessage}</p>
                                            {search && <p className="mt-1 text-xs text-muted-foreground">No results for "{search}"</p>}
                                        </div>
                                    )}
                                </CommandEmpty>
                            )}
                            {!loading && options.length > 0 && (
                                <CommandGroup>
                                    {mode === 'multiple' && maxSelections && (
                                        <div className="border-b px-2 py-1.5 text-xs text-muted-foreground">
                                            {selectedOptions.length} of {maxSelections} selected
                                        </div>
                                    )}
                                    {options.map((option, index) => {
                                        const optionValue = getOptionValue(option);
                                        const isSelected = currentValues.some((val) => String(val) === String(optionValue));
                                        const isDisabled =
                                            mode === 'multiple' && maxSelections && !isSelected && selectedOptions.length >= maxSelections;

                                        return (
                                            <CommandItem
                                                key={`${optionValue}-${index}`}
                                                value={String(optionValue)} // Ensure string value for Command component
                                                onSelect={handleSelect}
                                                disabled={isDisabled as boolean}
                                                className={cn(
                                                    'cursor-pointer transition-colors',
                                                    'hover:bg-accent hover:text-white',
                                                    isSelected && 'bg-accent text-white',
                                                    isDisabled && 'cursor-not-allowed opacity-50',
                                                )}
                                            >
                                                <Check className={cn('mr-2 h-4 w-4 transition-opacity', isSelected ? 'opacity-100 text-white' : 'opacity-0')} />
                                                <div className="flex-1 truncate">{renderOptionContent(option)}</div>
                                            </CommandItem>
                                        );
                                    })}
                                    {maxResults && options.length >= maxResults && (
                                        <div className="border-t px-2 py-1.5 text-xs text-muted-foreground">
                                            Showing first {maxResults} results. Refine your search for more specific results.
                                        </div>
                                    )}
                                </CommandGroup>
                            )}
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            {errorMessage && (
                <p className="mt-1 text-sm text-destructive" role="alert">
                    {errorMessage}
                </p>
            )}
        </div>
    );
}
