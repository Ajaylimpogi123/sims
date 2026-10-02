import { BellIcon } from "lucide-react";
import { Link, router, usePage } from "@inertiajs/react";
import { Button } from "@/Components/ui/button";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";
import { formatDateTime } from "@/lib/dates";

export function NotificationBell() {
    const { unreadNotificationsCount = 0, recentNotifications = [] } =
        usePage().props;

    const markRead = (notification, e) => {
        e.preventDefault();

        if (notification.read_at) return;

        router.patch(
            route("notifications.read", notification.id),
            {},
            { preserveScroll: true },
        );
    };

    const markAllRead = (e) => {
        e.preventDefault();
        e.stopPropagation();
        router.patch(route("notifications.read-all"), {}, { preserveScroll: true });
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="relative">
                    <BellIcon className="h-5 w-5" />
                    {unreadNotificationsCount > 0 && (
                        <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold text-white">
                            {unreadNotificationsCount > 9
                                ? "9+"
                                : unreadNotificationsCount}
                        </span>
                    )}
                    <span className="sr-only">Notifications</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80">
                <DropdownMenuLabel className="flex items-center justify-between">
                    <span>Notifications</span>
                    {unreadNotificationsCount > 0 && (
                        <button
                            type="button"
                            onClick={markAllRead}
                            className="text-xs font-normal text-emerald-600 hover:underline"
                        >
                            Mark all as read
                        </button>
                    )}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />

                {recentNotifications.length === 0 ? (
                    <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                        No notifications yet.
                    </p>
                ) : (
                    recentNotifications.map((notification) => (
                        <DropdownMenuItem
                            key={notification.id}
                            className="flex flex-col items-start gap-0.5 whitespace-normal py-2"
                            onClick={(e) => markRead(notification, e)}
                        >
                            <span className="flex w-full items-center gap-2 text-sm font-medium">
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
                        </DropdownMenuItem>
                    ))
                )}

                <DropdownMenuSeparator />
                <DropdownMenuItem asChild className="justify-center text-sm">
                    <Link href={route("notifications.index")}>
                        View all notifications
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
