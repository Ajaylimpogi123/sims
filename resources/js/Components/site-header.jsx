import { usePage } from "@inertiajs/react";
import { Separator } from "@/Components/ui/separator";
import { SidebarTrigger } from "@/Components/ui/sidebar";
import { NotificationBell } from "@/Components/NotificationBell";
import { TermSwitcher } from "@/Components/TermSwitcher";

// Inertia page component -> header title. Unlisted pages show no title.
const PAGE_TITLES = {
    "Dashboard/Index": "Dashboard",
    "UserManagement/Index": "User Management",
    "CompanyManagement/Index": "Company Management",
    "InternshipAssignment/Index": "Internship Assignment",
    "AttendanceMonitoring/Index": "Attendance Monitoring",
    "AttendanceApprovals/Index": "Pending Approvals",
    "ReportReviews/Index": "Report Reviews",
    "ProgressMonitoring/Index": "Progress Monitoring",
    "SupervisorEvaluations/Index": "Supervisor Monitoring & Feedback",
    "SupervisorEvaluations/Show": "Evaluation Detail",
    "SupervisorEvaluations/MyFeedback": "My Feedback",
    "EvaluationCriteria/Index": "Evaluation Criteria",
    "Terms/Index": "Terms",
    "Attendance/Index": "My Attendance",
    "InternshipReports/Index": "My Reports",
    "Notifications/Index": "Notifications",
    "Profile/Edit": "Profile",
};

export function SiteHeader() {
    const { component } = usePage();
    const title = PAGE_TITLES[component];

    return (
        <header className="group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 flex h-12 shrink-0 items-center gap-2 border-b transition-[width,height] ease-linear">
            <div className="flex w-full min-w-0 items-center gap-1 px-4 lg:gap-2 lg:px-6">
                <SidebarTrigger className="-ml-1" />
                {title && (
                    <>
                        <Separator
                            orientation="vertical"
                            className="mx-2 hidden data-[orientation=vertical]:h-4 sm:block"
                        />
                        <h1 className="hidden truncate text-base font-medium sm:block">
                            {title}
                        </h1>
                    </>
                )}
                <div className="ml-auto flex min-w-0 items-center gap-2">
                    <TermSwitcher />
                    <NotificationBell />
                </div>
            </div>
        </header>
    );
}
