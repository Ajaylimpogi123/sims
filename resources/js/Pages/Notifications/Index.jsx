import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, router } from "@inertiajs/react";
import { Button } from "@/Components/ui/button";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import { formatDateTime } from "@/lib/dates";

export default function Index({ notifications }) {
    const items = notifications.data ?? [];

    const markRead = (notification) => {
        if (notification.read_at) return;

        router.patch(
            route("notifications.read", notification.id),
            {},
            { preserveScroll: true },
        );
    };

    const markAllRead = () => {
        router.patch(route("notifications.read-all"), {}, { preserveScroll: true });
    };

    const goToPage = (page) => {
        router.get(
            route("notifications.index"),
            { page },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AuthenticatedLayout>
            <Head title="Notifications" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-white">
                                Notifications
                            </h1>
                            <p className="mt-2 text-sm text-white">
                                Stay on top of approvals, reviews, and
                                assignment updates
                            </p>
                        </div>
                        <Button variant="secondary" onClick={markAllRead}>
                            Mark all as read
                        </Button>
                    </div>

                    <div className="rounded-sm border bg-card text-card-foreground shadow">
                        <div className="p-6">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Title</TableHead>
                                        <TableHead>Message</TableHead>
                                        <TableHead>Received</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {items.length ? (
                                        items.map((notification) => (
                                            <TableRow
                                                key={notification.id}
                                                className={
                                                    notification.read_at
                                                        ? undefined
                                                        : "bg-emerald-50/60 cursor-pointer"
                                                }
                                                onClick={() =>
                                                    markRead(notification)
                                                }
                                            >
                                                <TableCell className="font-medium">
                                                    {notification.title}
                                                </TableCell>
                                                <TableCell className="max-w-md text-muted-foreground">
                                                    {notification.body}
                                                </TableCell>
                                                <TableCell>
                                                    {formatDateTime(
                                                        notification.created_at,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {notification.read_at ? (
                                                        <span className="text-xs text-muted-foreground">
                                                            Read
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                                            New
                                                        </span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={4}
                                                className="h-24 text-center"
                                            >
                                                No notifications yet.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </div>

                    {notifications.last_page > 1 && (
                        <div className="flex items-center justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    goToPage(notifications.current_page - 1)
                                }
                                disabled={notifications.current_page <= 1}
                            >
                                Previous
                            </Button>
                            <span className="text-sm text-white">
                                Page {notifications.current_page} of{" "}
                                {notifications.last_page}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    goToPage(notifications.current_page + 1)
                                }
                                disabled={
                                    notifications.current_page >=
                                    notifications.last_page
                                }
                            >
                                Next
                            </Button>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
