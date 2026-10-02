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
    Handshake,
    Clock,
    ClipboardCheck,
    Star,
    SlidersHorizontal,
} from "lucide-react";

import { NavDocuments } from "@/Components/nav-documents";
import { NavMain } from "@/Components/nav-main";
import { NavSecondary } from "@/Components/nav-secondary";
import { NavUser } from "@/Components/nav-user";
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/Components/ui/sidebar";
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

    const supervisorEvaluationsItem = {
        title: "Supervisor Monitoring & Feedback",
        url: route("supervisor-evaluations.index"),
        icon: Star,
    };

    const evaluationCriteriaItem = {
        title: "Evaluation Criteria",
        url: route("evaluation-criteria.index"),
        icon: SlidersHorizontal,
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
                  supervisorEvaluationsItem,
                  evaluationCriteriaItem,
              ]
            : roleId === 2
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
                        title: "Internship Assignment",
                        url: route("internship-assignment.index"),
                        icon: Handshake,
                    },
                    {
                        title: "Attendance Monitoring",
                        url: route("attendance-monitoring.index"),
                        icon: Clock,
                    },
                    reportReviewsItem,
                    progressMonitoringItem,
                    supervisorEvaluationsItem,
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
                      {
                          title: "My Feedback",
                          url: route("my-feedback.index"),
                          icon: Star,
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
                        pendingApprovalsItem,
                        reportReviewsItem,
                        progressMonitoringItem,
                        supervisorEvaluationsItem,
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
