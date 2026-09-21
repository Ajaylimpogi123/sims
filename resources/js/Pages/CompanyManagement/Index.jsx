import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Plus } from "lucide-react";
import AddModal from "./Partials/AddModal";
import { DataTable } from "./Partials/DataTable";
import { getColumns } from "./Partials/Columns";

export default function Index({ companies, availableSupervisors }) {
    const columns = getColumns(availableSupervisors);

    const companyData =
        companies.map((company) => ({
            id: company.id,
            company_name: company.company_name,
            address: company.address,
            contact_person: company.contact_person,
            contact_number: company.contact_number,
            email: company.email,
            industry: company.industry,
            slots: company.slots,
            status: company.status,
            students_count: company.students_count,
            supervisors: company.supervisors || [],
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

                            <AddModal>
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
