import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import { Link } from "@inertiajs/react";
import { Bell } from "lucide-react";
import EmptyState from "@/Components/EmptyState";
import { formatDateTime } from "@/lib/dates";

/**
 * Reuses the same notification-row markup/styling as the notification bell
 * dropdown (resources/js/Components/NotificationBell.jsx) rather than
 * inventing new styling for this feed.
 */
export default function RecentActivity({ recentActivity }) {
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
                            <li
                                key={notification.id}
                                className="flex flex-col items-start gap-0.5 py-3 text-sm"
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
