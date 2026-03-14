import { ImagePlus, X } from 'lucide-react';
import * as React from 'react';
import { cn } from '@/lib/utils';
import type { MediaItem } from '@/types/media';

type FileUploadProps = {
    multiple?: boolean;
    accept?: string;
    existingMedia?: MediaItem[];
    onChange: (files: File[]) => void;
    onRemoveExisting?: (ids: number[]) => void;
    disabled?: boolean;
    className?: string;
    maxFiles?: number;
};

export function FileUpload({
    multiple = false,
    accept = 'image/*',
    existingMedia = [],
    onChange,
    onRemoveExisting,
    disabled = false,
    className,
    maxFiles,
}: FileUploadProps) {
    const [newFiles, setNewFiles] = React.useState<File[]>([]);
    const [removedMediaIds, setRemovedMediaIds] = React.useState<number[]>([]);
    const [dragOver, setDragOver] = React.useState(false);
    const inputRef = React.useRef<HTMLInputElement>(null);

    const visibleExisting = existingMedia.filter(
        (m) => !removedMediaIds.includes(m.id),
    );

    const totalCount = visibleExisting.length + newFiles.length;
    const canAddMore = !maxFiles || totalCount < maxFiles;
    const effectiveMaxFiles = multiple ? maxFiles : 1;

    const previews = React.useMemo(
        () => newFiles.map((f) => ({ file: f, url: URL.createObjectURL(f) })),
        [newFiles],
    );

    React.useEffect(() => {
        return () => previews.forEach((p) => URL.revokeObjectURL(p.url));
    }, [previews]);

    const addFiles = (incoming: File[]) => {
        if (disabled) return;

        if (!multiple) {
            setNewFiles(incoming.slice(0, 1));
            onChange(incoming.slice(0, 1));
            return;
        }

        const allowed = effectiveMaxFiles
            ? effectiveMaxFiles - visibleExisting.length - newFiles.length
            : incoming.length;
        const toAdd = incoming.slice(0, Math.max(0, allowed));
        if (toAdd.length === 0) return;

        const next = [...newFiles, ...toAdd];
        setNewFiles(next);
        onChange(next);
    };

    const removeNew = (index: number) => {
        const next = newFiles.filter((_, i) => i !== index);
        setNewFiles(next);
        onChange(next);
    };

    const removeExisting = (id: number) => {
        const next = [...removedMediaIds, id];
        setRemovedMediaIds(next);
        onRemoveExisting?.(next);
    };

    const handleDrop = (e: React.DragEvent) => {
        e.preventDefault();
        setDragOver(false);
        if (disabled) return;
        const files = Array.from(e.dataTransfer.files).filter((f) =>
            accept === '*' ? true : f.type.match(accept.replace('*', '.*')),
        );
        addFiles(files);
    };

    const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(e.target.files ?? []);
        addFiles(files);
        e.target.value = '';
    };

    const hasItems = visibleExisting.length > 0 || newFiles.length > 0;

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            {/* Preview grid */}
            {hasItems && (
                <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6">
                    {/* Existing media */}
                    {visibleExisting.map((media) => (
                        <div
                            key={`existing-${media.id}`}
                            className="group relative aspect-square overflow-hidden rounded-lg border bg-muted"
                        >
                            <img
                                src={media.preview_url || media.original_url}
                                alt={media.file_name}
                                className="h-full w-full object-cover"
                            />
                            {!disabled && (
                                <button
                                    type="button"
                                    onClick={() => removeExisting(media.id)}
                                    className="absolute right-1 top-1 rounded-full bg-black/60 p-1 text-white opacity-0 transition-opacity hover:bg-black/80 group-hover:opacity-100"
                                >
                                    <X className="size-3" />
                                </button>
                            )}
                        </div>
                    ))}

                    {/* New file previews */}
                    {previews.map((preview, index) => (
                        <div
                            key={`new-${index}`}
                            className="group relative aspect-square overflow-hidden rounded-lg border border-dashed border-primary/30 bg-muted"
                        >
                            <img
                                src={preview.url}
                                alt={preview.file.name}
                                className="h-full w-full object-cover"
                            />
                            {!disabled && (
                                <button
                                    type="button"
                                    onClick={() => removeNew(index)}
                                    className="absolute right-1 top-1 rounded-full bg-black/60 p-1 text-white opacity-0 transition-opacity hover:bg-black/80 group-hover:opacity-100"
                                >
                                    <X className="size-3" />
                                </button>
                            )}
                            <div className="absolute bottom-0 left-0 right-0 bg-black/50 px-1.5 py-0.5">
                                <p className="truncate text-[10px] text-white">
                                    {preview.file.name}
                                </p>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Drop zone */}
            {(canAddMore || !hasItems) && (
                <div
                    onDragOver={(e) => {
                        e.preventDefault();
                        if (!disabled) setDragOver(true);
                    }}
                    onDragLeave={() => setDragOver(false)}
                    onDrop={handleDrop}
                    onClick={() => !disabled && inputRef.current?.click()}
                    className={cn(
                        'flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-4 py-8 text-center transition-colors',
                        dragOver
                            ? 'border-primary bg-primary/5'
                            : 'border-muted-foreground/25 hover:border-muted-foreground/50',
                        disabled && 'cursor-not-allowed opacity-50',
                    )}
                >
                    <ImagePlus className="mb-2 size-8 text-muted-foreground/50" />
                    <p className="text-sm font-medium text-muted-foreground">
                        {dragOver
                            ? 'Drop files here'
                            : 'Click to browse or drag and drop'}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground/70">
                        {multiple
                            ? effectiveMaxFiles
                                ? `Up to ${effectiveMaxFiles} files`
                                : 'Multiple files allowed'
                            : 'Single file only'}
                    </p>
                    <input
                        ref={inputRef}
                        type="file"
                        accept={accept}
                        multiple={multiple}
                        onChange={handleInputChange}
                        disabled={disabled}
                        className="hidden"
                    />
                </div>
            )}
        </div>
    );
}
