import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import { Link, usePage } from "@inertiajs/react";
import { Bell } from "lucide-react";
import EmptyState from "@/Components/EmptyState";
import { formatDateTime } from "@/lib/dates";

/**
 * Own notifications open through notifications.open (marks read + redirects).
 * Others' notifications (a supervisor seeing a student's) link straight to the
 * server-resolved `url` so the student's notification is not marked read.
 */
function activityHref(notification, viewerId) {
    if (String(notification.user_id) === String(viewerId)) {
        return route("notifications.open", notification.id);
    }

    return notification.url || route("notifications.index");
}

/**
 * Reuses the same notification-row markup/styling as the notification bell
 * dropdown (resources/js/Components/NotificationBell.jsx) rather than
 * inventing new styling for this feed.
 */
export default function RecentActivity({ recentActivity }) {
    const { auth } = usePage().props;
    const viewerId = auth?.user?.id;

    return (
        <Card className="border-0 shadow-sm">
            <CardHeader>
                <CardTitle>Recent Activity</CardTitle>
            </CardHeader>
            <CardContent>
                {recentActivity.length === 0 ? (
                    <EmptyState
                        icon={Bell}
                        title="No recent activity"
                        message="Notifications will show up here as things happen."
                    />
                ) : (
                    <ul className="divide-y">
                        {recentActivity.map((notification) => (
                            <li key={notification.id}>
                                <Link
                                    href={activityHref(notification, viewerId)}
                                    className="-mx-2 flex flex-col items-start gap-0.5 rounded-sm px-2 py-3 text-sm transition-colors hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <span className="flex w-full items-center gap-2 font-medium">
                                        {!notification.read_at && (
                                            <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-500" />
                                        )}
                                        {notification.title}
                                    </span>
                                    {notification.body && (
                                        <span className="line-clamp-2 text-xs text-muted-foreground">
                                            {notification.body}
                                        </span>
                                    )}
                                    <span className="text-[11px] text-muted-foreground">
                                        {formatDateTime(notification.created_at)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <div className="mt-3 text-center">
                    <Link
                        href={route("notifications.index")}
                        className="text-xs font-medium text-emerald-600 hover:underline"
                    >
                        View all notifications
                    </Link>
                </div>
            </CardContent>
        </Card>
    );
}
