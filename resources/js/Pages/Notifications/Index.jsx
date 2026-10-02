import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, Link, router } from "@inertiajs/react";
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

    // Opening goes through notifications.open (a single GET that marks the
    // notification read and redirects to its destination).
    const openHref = (notification) =>
        route("notifications.open", notification.id);

    // Whole-row click target for pointer users; the title <Link> is the
    // keyboard-focusable control, so the row itself is not a tab stop.
    const handleRowClick = (notification, e) => {
        if (e.target.closest("a, button")) return;
        if (window.getSelection()?.toString()) return;

        const href = openHref(notification);

        if (e.metaKey || e.ctrlKey) {
            window.open(href, "_blank", "noopener");
            return;
        }

        router.visit(href);
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
                                                        ? "cursor-pointer"
                                                        : "cursor-pointer bg-emerald-50/60"
                                                }
                                                onClick={(e) =>
                                                    handleRowClick(
                                                        notification,
                                                        e,
                                                    )
                                                }
                                            >
                                                <TableCell className="font-medium">
                                                    <Link
                                                        href={openHref(
                                                            notification,
                                                        )}
                                                        className="rounded-sm hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                    >
                                                        {notification.title}
                                                    </Link>
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
