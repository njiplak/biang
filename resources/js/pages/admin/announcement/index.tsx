import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Send, Trash, Undo2 } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@/layouts/admin-layout';
import { createFormResponse } from '@/lib/constant';
import admin from '@/routes/admin';
import type { AdminAnnouncement } from '@/types/catalog';

const SEVERITY_TONE: Record<string, string> = {
    info: 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    warning: 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    critical: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
};

function formatDate(value: string | null) {
    if (!value) return null;
    return new Date(value).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** Section 10: "Talk to everyone. Announce maintenance or a new feature." */
export default function AnnouncementIndex({
    announcements,
}: {
    announcements: AdminAnnouncement[];
}) {
    return (
        <div className="flex flex-col gap-4">
            <Head title="Announcements" />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex flex-col">
                    <h1 className="text-xl font-semibold">Announcements</h1>
                    <p className="text-sm text-muted-foreground">
                        Shown in the app until dismissed. Dismissal is per
                        person, so nobody is told twice per workspace.
                    </p>
                </div>
                <AnnouncementDialog />
            </div>

            <div className="flex flex-col gap-3">
                {announcements.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Nothing announced yet.
                    </p>
                )}
                {announcements.map((announcement) => (
                    <Card key={announcement.id}>
                        <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0">
                            <div>
                                <CardTitle className="flex flex-wrap items-center gap-2 text-base">
                                    {announcement.title}
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${SEVERITY_TONE[announcement.severity]}`}
                                    >
                                        {announcement.severity}
                                    </span>
                                    {announcement.is_live ? (
                                        <Badge>Live</Badge>
                                    ) : (
                                        <Badge variant="outline">Draft</Badge>
                                    )}
                                    {!announcement.is_dismissible && (
                                        <Badge variant="secondary">
                                            Cannot be dismissed
                                        </Badge>
                                    )}
                                </CardTitle>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {announcement.audience === 'all'
                                        ? 'Everyone'
                                        : announcement.audience === 'plan'
                                          ? `Plans: ${announcement.audience_filter?.plan_codes?.join(', ') ?? 'none'}`
                                          : `States: ${announcement.audience_filter?.states?.join(', ') ?? 'none'}`}
                                    {announcement.created_by
                                        ? ` · by ${announcement.created_by}`
                                        : ''}
                                    {announcement.published_at
                                        ? ` · published ${formatDate(announcement.published_at)}`
                                        : ''}
                                    {` · ${announcement.dismissals_count} dismissed`}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {announcement.published_at === null ? (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                admin.announcement.publish(
                                                    announcement.id,
                                                ).url,
                                            )
                                        }
                                    >
                                        <Send className="size-4" />
                                        Publish
                                    </Button>
                                ) : (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.delete(
                                                admin.announcement.unpublish(
                                                    announcement.id,
                                                ).url,
                                            )
                                        }
                                    >
                                        <Undo2 className="size-4" />
                                        Unpublish
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="text-red-600 hover:text-red-600"
                                    onClick={() =>
                                        router.delete(
                                            admin.announcement.destroy(
                                                announcement.id,
                                            ).url,
                                        )
                                    }
                                >
                                    <Trash className="size-4" />
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="text-sm whitespace-pre-line text-muted-foreground">
                            {announcement.body}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </div>
    );
}

function AnnouncementDialog() {
    const [open, setOpen] = useState(false);
    const form = useForm({
        title: '',
        body: '',
        audience: 'all',
        severity: 'info',
        is_dismissible: true,
        expires_at: '',
        plan_codes: [] as string[],
        states: [] as string[],
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.transform((data: any) => ({
            ...data,
            expires_at: data.expires_at || null,
        }));

        form.post(admin.announcement.store().url, {
            ...createFormResponse('Announcement saved as a draft.'),
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus className="size-4" />
                    New announcement
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>New announcement</DialogTitle>
                        <DialogDescription>
                            Saved as a draft. Nobody sees it until you publish.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="title">Title</Label>
                        <Input
                            id="title"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                        <InputError message={form.errors.title} />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="body">Message</Label>
                        <Textarea
                            id="body"
                            rows={4}
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                        />
                        <InputError message={form.errors.body} />
                    </div>

                    <div className="flex gap-3">
                        <div className="flex flex-1 flex-col gap-1.5">
                            <Label htmlFor="severity">Severity</Label>
                            <Select
                                value={form.data.severity}
                                onValueChange={(value) =>
                                    form.setData('severity', value)
                                }
                            >
                                <SelectTrigger id="severity">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="info">Info</SelectItem>
                                    <SelectItem value="warning">
                                        Warning
                                    </SelectItem>
                                    <SelectItem value="critical">
                                        Critical
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex flex-1 flex-col gap-1.5">
                            <Label htmlFor="expires">Expires (optional)</Label>
                            <Input
                                id="expires"
                                type="date"
                                value={form.data.expires_at}
                                onChange={(e) =>
                                    form.setData('expires_at', e.target.value)
                                }
                            />
                            <InputError message={form.errors.expires_at} />
                        </div>
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_dismissible}
                            onChange={(e) =>
                                form.setData('is_dismissible', e.target.checked)
                            }
                        />
                        Customers can dismiss it
                    </label>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save draft
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

AnnouncementIndex.layout = (page: React.ReactNode) => (
    <AdminLayout>{page}</AdminLayout>
);
