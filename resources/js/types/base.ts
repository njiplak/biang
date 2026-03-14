export type Base<T> = {
    prev_page?: number | null;
    items?: T;
    current_page?: number | null;
    next_page?: number | null;
    total_page?: number | null;
};

export type FilterProps = {
    updateParams?: (params: Record<string, any>) => void;
    currentParams?: Record<string, any>;
};
