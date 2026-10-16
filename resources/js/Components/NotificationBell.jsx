import {
    ArrowRight,
    BellIcon,
    BellOff,
    Briefcase,
    CalendarCheck,
    CheckCheck,
    Clock,
    FileCheck,
    FileText,
    UserPlus,
} from "lucide-react";
import { Link, router, usePage } from "@inertiajs/react";
import { Button } from "@/Components/ui/button";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";
import { formatDateTime, formatRelativeTime } from "@/lib/dates";
import { cn } from "@/lib/utils";

/** Icon and tint per notification type (NotificationService). */
const TYPE_STYLES = {
    attendance_pending: { icon: Clock, tint: "bg-amber-100 text-amber-700" },
    attendance_reviewed: { icon: CalendarCheck, tint: "bg-emerald-100 text-emerald-700" },
    report_submitted: { icon: FileText, tint: "bg-sky-100 text-sky-700" },
    report_reviewed: { icon: FileCheck, tint: "bg-emerald-100 text-emerald-700" },
    assignment_updated: { icon: Briefcase, tint: "bg-violet-100 text-violet-700" },
    student_registered: { icon: UserPlus, tint: "bg-teal-100 text-teal-700" },
};
const FALLBACK_STYLE = { icon: BellIcon, tint: "bg-muted text-muted-foreground" };

export function NotificationBell() {
    const { unreadNotificationsCount = 0, recentNotifications = [] } =
        usePage().props;

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
                        <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold text-white ring-2 ring-background">
                            {unreadNotificationsCount > 9
                                ? "9+"
                                : unreadNotificationsCount}
                        </span>
                    )}
                    <span className="sr-only">Notifications</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="w-[22rem] overflow-hidden rounded-xl p-0 shadow-xl"
            >
                <div className="flex items-center justify-between border-b px-4 py-3">
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold">
                            Notifications
                        </span>
                        {unreadNotificationsCount > 0 && (
                            <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-semibold text-primary">
                                {unreadNotificationsCount} new
                            </span>
                        )}
                    </div>
                    {unreadNotificationsCount > 0 && (
                        <button
                            type="button"
                            onClick={markAllRead}
                            className="inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-xs font-medium text-primary transition-colors hover:bg-primary/10"
                        >
                            <CheckCheck className="h-3.5 w-3.5" />
                            Mark all as read
                        </button>
                    )}
                </div>

                {recentNotifications.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-4 py-10 text-center">
                        <span className="flex h-11 w-11 items-center justify-center rounded-full bg-muted">
                            <BellOff className="h-5 w-5 text-muted-foreground" />
                        </span>
                        <p className="text-sm font-medium">You're all caught up</p>
                        <p className="text-xs text-muted-foreground">
                            New notifications will show up here.
                        </p>
                    </div>
                ) : (
                    <div className="max-h-[26rem] divide-y overflow-y-auto">
                        {/* Each item is a single GET to notifications.open: the
                            server marks it read and redirects to the destination. */}
                        {recentNotifications.map((notification) => {
                            const unread = !notification.read_at;
                            const { icon: Icon, tint } =
                                TYPE_STYLES[notification.type] ?? FALLBACK_STYLE;

                            return (
                                <DropdownMenuItem
                                    key={notification.id}
                                    asChild
                                    className={cn(
                                        "cursor-pointer items-start gap-3 whitespace-normal rounded-none px-4 py-3",
                                        unread && "bg-primary/[0.04]",
                                    )}
                                >
                                    <Link
                                        href={route(
                                            "notifications.open",
                                            notification.id,
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                "mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full",
                                                tint,
                                            )}
                                        >
                                            <Icon className="h-4 w-4" />
                                        </span>
                                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                            <span
                                                className={cn(
                                                    "text-sm leading-snug",
                                                    unread
                                                        ? "font-semibold text-foreground"
                                                        : "font-medium text-foreground/75",
                                                )}
                                            >
                                                {notification.title}
                                            </span>
                                            {notification.body && (
                                                <span className="line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                                                    {notification.body}
                                                </span>
                                            )}
                                            <span
                                                className="text-[11px] text-muted-foreground/80"
                                                title={formatDateTime(
                                                    notification.created_at,
                                                )}
                                            >
                                                {formatRelativeTime(
                                                    notification.created_at,
                                                )}
                                            </span>
                                        </span>
                                        {unread && (
                                            <span
                                                className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary"
                                                aria-label="Unread"
                                            />
                                        )}
                                    </Link>
                                </DropdownMenuItem>
                            );
                        })}
                    </div>
                )}

                <DropdownMenuItem
                    asChild
                    className="cursor-pointer justify-center gap-1.5 rounded-none border-t bg-muted/40 py-2.5 text-sm font-medium text-primary focus:bg-primary/10 focus:text-primary"
                >
                    <Link href={route("notifications.index")}>
                        View all notifications
                        <ArrowRight className="h-4 w-4" />
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
