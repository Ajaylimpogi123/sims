import { Link, usePage } from "@inertiajs/react";
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/Components/ui/sidebar";
import { cn } from "@/lib/utils";

/** Path of an absolute route() URL or a relative one, without the query. */
const pathOf = (url) => {
    try {
        return new URL(url, window.location.origin).pathname.replace(/\/+$/, "") || "/";
    } catch {
        return url;
    }
};

/**
 * The current page is the item's page or one under it. Admins land on
 * /admin-dashboard (same page), which counts as Dashboard.
 */
const isActive = (current, itemUrl) => {
    const path = pathOf(itemUrl);
    if (path === "/dashboard" && current === "/admin-dashboard") return true;
    return current === path || current.startsWith(`${path}/`);
};

export function NavMain({ items }) {
    const current = pathOf(usePage().url);

    return (
        <SidebarGroup>
            <SidebarGroupLabel className="px-3 text-[11px] font-semibold uppercase tracking-wider text-sidebar-foreground/50">
                Menu
            </SidebarGroupLabel>
            <SidebarGroupContent>
                <SidebarMenu className="gap-1">
                    {items.map((item) => {
                        const active = isActive(current, item.url);

                        return (
                            <SidebarMenuItem key={item.url}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={active}
                                    tooltip={item.title}
                                    className={cn(
                                        "relative h-10 gap-3 rounded-lg px-3 font-medium text-sidebar-foreground/75 transition-colors",
                                        "hover:bg-primary/[0.06] hover:text-primary-strong",
                                        "data-[active=true]:bg-primary/10 data-[active=true]:font-semibold data-[active=true]:text-primary-strong",
                                        "before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-r-full before:bg-primary before:opacity-0 before:transition-opacity data-[active=true]:before:opacity-100",
                                        "[&>svg]:text-sidebar-foreground/50 [&:hover>svg]:text-primary [&[data-active=true]>svg]:text-primary",
                                    )}
                                >
                                    <Link
                                        href={item.url}
                                        aria-current={active ? "page" : undefined}
                                    >
                                        {item.icon && <item.icon />}
                                        <span className="flex-1 truncate">
                                            {item.title}
                                        </span>
                                        {item.badge > 0 && (
                                            <span className="ml-auto flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1.5 text-[10px] font-semibold text-white">
                                                {item.badge > 99 ? "99+" : item.badge}
                                            </span>
                                        )}
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        );
                    })}
                </SidebarMenu>
            </SidebarGroupContent>
        </SidebarGroup>
    );
}
