import * as React from "react";
import {
    ArrowUpCircleIcon,
    BarChartIcon,
    CameraIcon,
    ClipboardListIcon,
    DatabaseIcon,
    UserRound,
    FileCodeIcon,
    FileIcon,
    FileTextIcon,
    FolderIcon,
    HelpCircleIcon,
    LayoutDashboardIcon,
    ListIcon,
    SearchIcon,
    SettingsIcon,
    UsersIcon,
    Building2,
    GraduationCap,
    Handshake,
    Clock,
    ClipboardCheck,
} from "lucide-react";

import { NavDocuments } from "@/components/nav-documents";
import { NavMain } from "@/components/nav-main";
import { NavSecondary } from "@/components/nav-secondary";
import { NavUser } from "@/components/nav-user";
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/components/ui/sidebar";
import { usePage } from "@inertiajs/react";

// Role IDs: 1 = Student, 2 = Internship Coordinator, 3 = Supervisor, 4 = Administrator

export function AppSidebar({ ...props }) {
    const { auth, app, flash, pendingApprovalsCount } = usePage().props;

    const pendingApprovalsItem = {
        title: pendingApprovalsCount
            ? `Pending Approvals (${pendingApprovalsCount})`
            : "Pending Approvals",
        url: route("attendance-approvals.index"),
        icon: ClipboardCheck,
    };

    const reportReviewsItem = {
        title: "Report Reviews",
        url: route("report-reviews.index"),
        icon: FileTextIcon,
    };

    const progressMonitoringItem = {
        title: "Progress Monitoring",
        url: route("progress-monitoring.index"),
        icon: BarChartIcon,
    };

    const userData = {
        name: auth?.user?.name || "Guest",
        email: auth?.user?.email || "guest@example.com",
        avatar: auth?.user?.avatar || "/images/logo/bcc-logo.jpg",
    };

    const roleId = auth?.user?.role_id;

    const defaultNavMain = [
        {
            title: "Dashboard",
            url: route("dashboard"),
            icon: LayoutDashboardIcon,
        },
    ];

    const roleNavMain =
        roleId === 4
            ? [
                  {
                      title: "Dashboard",
                      url: route("dashboard"),
                      icon: LayoutDashboardIcon,
                  },
                  {
                      title: "User Management",
                      url: route("user-management.index"),
                      icon: UserRound,
                  },
                  {
                      title: "Company Management",
                      url: route("company-management.index"),
                      icon: Building2,
                  },
                  {
                      title: "Student Management",
                      url: route("student-management.index"),
                      icon: GraduationCap,
                  },
                  {
                      title: "Internship Assignment",
                      url: route("internship-assignment.index"),
                      icon: Handshake,
                  },
                  {
                      title: "Attendance Monitoring",
                      url: route("attendance-monitoring.index"),
                      icon: Clock,
                  },
                  pendingApprovalsItem,
                  reportReviewsItem,
                  progressMonitoringItem,
              ]
            : roleId === 2
              ? [
                    {
                        title: "Dashboard",
                        url: route("dashboard"),
                        icon: LayoutDashboardIcon,
                    },
                    {
                        title: "Company Management",
                        url: route("company-management.index"),
                        icon: Building2,
                    },
                    {
                        title: "Student Management",
                        url: route("student-management.index"),
                        icon: GraduationCap,
                    },
                    {
                        title: "Internship Assignment",
                        url: route("internship-assignment.index"),
                        icon: Handshake,
                    },
                    {
                        title: "Attendance Monitoring",
                        url: route("attendance-monitoring.index"),
                        icon: Clock,
                    },
                    pendingApprovalsItem,
                    reportReviewsItem,
                    progressMonitoringItem,
                ]
              : roleId === 1
                ? [
                      {
                          title: "Dashboard",
                          url: route("dashboard"),
                          icon: LayoutDashboardIcon,
                      },
                      {
                          title: "My Attendance",
                          url: route("attendance.index"),
                          icon: Clock,
                      },
                      {
                          title: "My Reports",
                          url: route("reports.index"),
                          icon: FileTextIcon,
                      },
                  ]
                : roleId === 3
                  ? [
                        {
                            title: "Dashboard",
                            url: route("dashboard"),
                            icon: LayoutDashboardIcon,
                        },
                        {
                            title: "Attendance Monitoring",
                            url: route("attendance-monitoring.index"),
                            icon: Clock,
                        },
                        reportReviewsItem,
                        progressMonitoringItem,
                    ]
                  : defaultNavMain;

    const navItems = {
        user: userData,
        navMain: roleNavMain,
        navClouds: [
            {
                title: "Capture",
                icon: CameraIcon,
                isActive: true,
                url: "#",
                items: [
                    { title: "Active Proposals", url: "#" },
                    { title: "Archived", url: "#" },
                ],
            },
            {
                title: "Proposal",
                icon: FileTextIcon,
                url: "#",
                items: [
                    { title: "Active Proposals", url: "#" },
                    { title: "Archived", url: "#" },
                ],
            },
            {
                title: "Prompts",
                icon: FileCodeIcon,
                url: "#",
                items: [
                    { title: "Active Proposals", url: "#" },
                    { title: "Archived", url: "#" },
                ],
            },
        ],
        navSecondary: [
            { title: "Settings", url: "#", icon: SettingsIcon },
            { title: "Get Help", url: "#", icon: HelpCircleIcon },
            { title: "Search", url: "#", icon: SearchIcon },
        ],
        documents: [],
    };

    if (flash?.success) {
        console.log("Flash success:", flash.success);
    }

    return (
        <Sidebar collapsible="offcanvas" {...props}>
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem className="flex justify-center">
                        <SidebarMenuButton
                            asChild
                            className="data-[slot=sidebar-menu-button]:!p-2 object-center h-15 w-28 p-0"
                        >
                            <a href="#">
                                <img
                                    src="/images/logo/bcc-logo.jpg"
                                    alt="Bacolod City College"
                                    className="object-contain"
                                />
                            </a>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>
            <SidebarContent>
                <NavMain items={navItems.navMain} />
                {navItems.documents.length > 0 && (
                    <NavDocuments items={navItems.documents} />
                )}
            </SidebarContent>
            <SidebarFooter>
                <NavUser user={navItems.user} />
            </SidebarFooter>
        </Sidebar>
    );
}
