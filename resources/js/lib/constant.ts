import type { VisitOptions } from '@inertiajs/core';
import { toast } from 'sonner';

const formatErrorMessages = (err: any) => {
    if (err.message) return err.message;
    if (Array.isArray(err)) return err.map((item) => item.message || item).join(', ');
    if (err.errors) return err.errors;
    return JSON.stringify(err);
};

export const createFormResponse = (successMessage?: string): VisitOptions => ({
    onSuccess: () => {
        toast.success(successMessage || 'Success...');
    },
    onError: (err) => {
        const errorMessage = formatErrorMessages(err);
        toast.error(errorMessage);
    },
});

export const FormResponse: VisitOptions = createFormResponse();