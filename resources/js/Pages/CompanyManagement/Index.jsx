import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, usePage } from "@inertiajs/react";
import { useMemo } from "react";
import { Button } from "@/Components/ui/button";
import { Plus } from "lucide-react";
import AddModal from "./Partials/AddModal";
import { DataTable } from "./Partials/DataTable";
import { getColumns } from "./Partials/Columns";

export default function Index({ companies, supervisorOptions = [] }) {
    const { flash } = usePage().props;

    const columns = useMemo(
        () => getColumns(supervisorOptions),
        [supervisorOptions],
    );

    const companyData =
        companies.map((company) => ({
            id: company.id,
            company_name: company.company_name,
            address: company.address,
            supervisor_id: company.supervisor_id ?? null,
            supervisor: company.supervisor ?? null,
            supervisor_issue: company.supervisor_issue ?? null,
            contact_number: company.contact_number,
            email: company.email,
            industry: company.industry,
            slots: company.slots,
            status: company.status,
            students_count: company.students_count,
        })) || [];

    return (
        <AuthenticatedLayout>
            <Head title="Company Management" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-8">
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight text-white">
                                    Companies
                                </h1>
                                <p className="mt-2 text-sm text-white">
                                    Manage internship partner companies and
                                    their available slots
                                </p>
                            </div>

                            <AddModal supervisorOptions={supervisorOptions}>
                                <Button
                                    size="sm"
                                    className="flex items-center gap-2"
                                >
                                    <Plus className="h-4 w-4" />
                                    Add Company
                                </Button>
                            </AddModal>
                        </div>
                    </div>

                    {/* Success only: delete refusals (flash.error) already
                        show in a dialog from the row action. */}
                    {flash?.success && (
                        <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                            {flash.success}
                        </div>
                    )}

                    <div className="rounded-sm border bg-card text-card-foreground shadow">
                        <div className="p-6">
                            <DataTable columns={columns} data={companyData} />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
