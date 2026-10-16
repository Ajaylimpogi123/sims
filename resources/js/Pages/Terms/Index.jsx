import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head, router, usePage } from "@inertiajs/react";
import { Plus } from "lucide-react";
import { Button } from "@/Components/ui/button";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/Components/ui/table";
import RowActionsMenu from "@/Components/RowActionsMenu";
import { formatLongDate } from "@/lib/dates";
import AddModal from "./Partials/AddModal";
import EditModal from "./Partials/EditModal";
import { schoolYearLabel } from "./Partials/TermFormFields";

const DESTRUCTIVE_ITEM_CLASS =
    "pl-2 pr-4 py-2 text-sm text-red-600 hover:bg-red-50 rounded-md cursor-pointer";

function TermStatusBadge({ isCurrent }) {
    return isCurrent ? (
        <span className="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">
            Current
        </span>
    ) : (
        <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
            Archived
        </span>
    );
}

function termDates(term) {
    if (!term.starts_on && !term.ends_on) return "—";
    return `${formatLongDate(term.starts_on)} — ${formatLongDate(term.ends_on)}`;
}

// Why Delete is unavailable; the server refuses the same cases.
function deleteBlockedReason(term) {
    if (term.can_delete) return null;
    if (term.is_current) return "Current term";
    if (term.students_count > 0) return "Has students";
    return "Can't be deleted";
}

export default function Index({ termList = [], semesters }) {
    const { flash } = usePage().props;

    const setCurrent = (term) => {
        if (
            confirm(
                `Make ${term.label} the current term? New registrations and placements will go to it, and other terms become read-only.`,
            )
        ) {
            router.post(
                route("terms.set-current", term.id),
                {},
                { preserveScroll: true },
            );
        }
    };

    const handleDelete = (term) => {
        if (confirm(`Delete ${term.label}?`)) {
            router.delete(route("terms.destroy", term.id), {
                preserveScroll: true,
            });
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Terms" />

            <div className="relative z-10 py-8">
                <div className="flex-1 space-y-6 p-4 md:p-6">
                    <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 className="text-3xl font-bold tracking-tight text-white">
                                Terms
                            </h1>
                            <p className="mt-2 text-sm text-white">
                                School years and semesters. New registrations
                                and placements go to the current term; past
                                terms are read-only.
                            </p>
                        </div>
                        <AddModal semesters={semesters}>
                            <Button
                                size="sm"
                                className="flex items-center gap-2"
                            >
                                <Plus className="h-4 w-4" />
                                Add Term
                            </Button>
                        </AddModal>
                    </div>

                    {flash?.success && (
                        <div className="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                            {flash.error}
                        </div>
                    )}

                    <div className="rounded-sm border bg-card text-card-foreground shadow">
                        <div className="p-6">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>School Year</TableHead>
                                        <TableHead>Semester</TableHead>
                                        <TableHead>Dates</TableHead>
                                        <TableHead>Students</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {termList.length ? (
                                        termList.map((term) => {
                                            const blocked =
                                                deleteBlockedReason(term);

                                            return (
                                                <TableRow key={term.id}>
                                                    <TableCell className="font-medium">
                                                        {schoolYearLabel(
                                                            term.school_year_start,
                                                        ) ?? "—"}
                                                    </TableCell>
                                                    <TableCell>
                                                        {term.semester_label}
                                                    </TableCell>
                                                    <TableCell className="whitespace-nowrap">
                                                        {termDates(term)}
                                                    </TableCell>
                                                    <TableCell>
                                                        {term.students_count ??
                                                            0}
                                                    </TableCell>
                                                    <TableCell>
                                                        <TermStatusBadge
                                                            isCurrent={
                                                                term.is_current
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        <RowActionsMenu
                                                            actions={[
                                                                {
                                                                    label: "Edit",
                                                                    dialog: "edit",
                                                                },
                                                                !term.is_current && {
                                                                    label: "Set as current",
                                                                    onSelect:
                                                                        () =>
                                                                            setCurrent(
                                                                                term,
                                                                            ),
                                                                },
                                                                {
                                                                    label: "Delete",
                                                                    disabled:
                                                                        Boolean(
                                                                            blocked,
                                                                        ),
                                                                    hint: blocked,
                                                                    className:
                                                                        DESTRUCTIVE_ITEM_CLASS,
                                                                    onSelect:
                                                                        () =>
                                                                            handleDelete(
                                                                                term,
                                                                            ),
                                                                },
                                                            ]}
                                                            renderDialogs={({
                                                                isOpen,
                                                                onOpenChange,
                                                            }) => (
                                                                <EditModal
                                                                    term={term}
                                                                    semesters={
                                                                        semesters
                                                                    }
                                                                    open={isOpen(
                                                                        "edit",
                                                                    )}
                                                                    onOpenChange={
                                                                        onOpenChange
                                                                    }
                                                                />
                                                            )}
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="h-24 text-center"
                                            >
                                                No terms yet. Add one, then set
                                                it as current to open
                                                registration.
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
