import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head } from "@inertiajs/react";
import { useEffect } from "react";
import RegistrationForm from "./Partials/RegistrationForm";
import UsersTable from "./Partials/UsersTable";
import { useRegistrationAlerts } from "./Hooks/useRegistrationAlerts";

export default function Index({ roles, users, filters }) {
    useRegistrationAlerts();

    useEffect(() => {
        if (window.location.hash === "#user-registration") {
            document.getElementById("user-registration")?.scrollIntoView({
                behavior: "smooth",
            });
        }
    }, []);

    return (
        <AuthenticatedLayout>
            <Head title="User Management" />

            <div className="relative z-10 py-8">
                <div className="mx-auto w-full min-w-0 max-w-full space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-white">
                            User Management
                        </h1>
                        <p className="mt-2 text-sm text-white">
                            Register users and manage accounts by role
                        </p>
                    </div>

                    <RegistrationForm roles={roles} />
                    <UsersTable
                        users={users}
                        roles={roles}
                        filters={filters}
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
