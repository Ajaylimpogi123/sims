import { SiteHeader } from "@/Components/site-header";
import { SidebarInset, SidebarProvider } from "@/Components/ui/sidebar";
import { AppSidebar } from "@/Components/app-sidebar";
import { Toaster } from "@/Components/ui/sonner";
export default function AuthenticatedLayout({ children }) {
    return (
        <SidebarProvider>
            <AppSidebar variant="inset" />
            <SidebarInset className="bg-muted">
                <SiteHeader />

                <main>
                    <div className="absolute left-0 w-full h-[280px] bg-gradient-to-br from-emerald-700 via-emerald-300 to-green-400 z-0 rounded-md"></div>
                    {children}
                </main>
                <Toaster />
            </SidebarInset>
        </SidebarProvider>
    );
}
