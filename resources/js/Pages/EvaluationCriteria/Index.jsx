import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, router, usePage } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Plus } from "lucide-react";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import AddModal from "./Partials/AddModal";
import EditModal from "./Partials/EditModal";

export default function Index({ criteria }) {
    const { flash } = usePage().props;

    const toggleActive = (criterion) => {
        router.patch(
            route("evaluation-criteria.toggle-active", criterion.id),
            {},
            { preserveScroll: true },
        );
    };

    const handleDelete = (criterion) => {
        const message = criterion.responses_count
            ? "This criterion already has evaluation responses, so it will be deactivated instead of deleted. Continue?"
            : "Delete this criterion?";

        if (confirm(message)) {
            router.delete(route("evaluation-criteria.destroy", criterion.id), {
                preserveScroll: true,
            });
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Evaluation Criteria" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-white">
                                Evaluation Criteria
                            </h1>
                            <p className="mt-2 text-sm text-white">
                                Configure the criteria supervisors rate
                                students against — add, edit, or deactivate
                                without touching the database
                            </p>
                        </div>
                        <AddModal>
                            <Button
                                size="sm"
                                className="flex items-center gap-2"
                            >
                                <Plus className="h-4 w-4" />
                                Add Criterion
                            </Button>
                        </AddModal>
                    </div>

                    {flash?.success && (
                        <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                            {flash.success}
                        </div>
                    )}

                    <div className="rounded-sm border bg-card text-card-foreground shadow">
                        <div className="p-6">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Label</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead>Sort</TableHead>
                                        <TableHead>Responses</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {criteria.length ? (
                                        criteria.map((criterion) => (
                                            <TableRow key={criterion.id}>
                                                <TableCell>
                                                    {criterion.category}
                                                </TableCell>
                                                <TableCell>
                                                    {criterion.label}
                                                </TableCell>
                                                <TableCell className="max-w-xs truncate">
                                                    {criterion.description ||
                                                        "-"}
                                                </TableCell>
                                                <TableCell>
                                                    {criterion.sort_order}
                                                </TableCell>
                                                <TableCell>
                                                    {criterion.responses_count}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            criterion.is_active
                                                                ? "default"
                                                                : "secondary"
                                                        }
                                                    >
                                                        {criterion.is_active
                                                            ? "Active"
                                                            : "Inactive"}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="flex flex-wrap gap-2">
                                                    <EditModal
                                                        criterion={criterion}
                                                    >
                                                        <Button size="sm">
                                                            Edit
                                                        </Button>
                                                    </EditModal>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            toggleActive(
                                                                criterion,
                                                            )
                                                        }
                                                    >
                                                        {criterion.is_active
                                                            ? "Deactivate"
                                                            : "Activate"}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="destructive"
                                                        onClick={() =>
                                                            handleDelete(
                                                                criterion,
                                                            )
                                                        }
                                                    >
                                                        Delete
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={7}
                                                className="h-24 text-center"
                                            >
                                                No criteria configured yet.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
