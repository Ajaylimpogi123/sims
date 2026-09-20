import InputLabel from "@/Components/InputLabel";
import { Button } from "@/components/ui/button";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import StatusBadge from "@/Components/StatusBadge";
import { router, useForm, usePage } from "@inertiajs/react";
import { Pencil, UserCheck, UserX } from "lucide-react";
import { useEffect, useMemo } from "react";
import Swal from "sweetalert2";
import EditModal from "./EditModal";

const SUPERADMIN_ROLE_ID = 3;

export default function UsersTable({ users, roles, filters }) {
    const { auth } = usePage().props;
    const roleId = auth?.user?.role_id;
    const currentUserId = auth?.user?.id;

    const { patch } = useForm();

    const availableRoles = useMemo(
        () =>
            roleId === 2
                ? roles.filter((role) => role.id !== SUPERADMIN_ROLE_ID)
                : roles,
        [roles, roleId],
    );

    const visibleUsers = useMemo(
        () =>
            roleId === 2
                ? users.data.filter((user) => user.role_id !== SUPERADMIN_ROLE_ID)
                : users.data,
        [users.data, roleId],
    );
    const { data: filterData, setData: setFilterData } = useForm({
        role_id: filters?.role_id || "",
        status: filters?.status || "",
    });

    useEffect(() => {
        setFilterData({
            role_id: filters?.role_id || "",
            status: filters?.status || "",
        });
    }, [filters]);

    const applyFilters = (overrides = {}) => {
        router.get(
            route("user-management.index"),
            {
                role_id: overrides.role_id ?? filterData.role_id,
                status: overrides.status ?? filterData.status,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ["users", "filters"],
            },
        );
    };

    const clearFilters = () => {
        setFilterData({ role_id: "", status: "" });
        router.get(
            route("user-management.index"),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ["users", "filters"],
            },
        );
    };

    const goToPage = (page) => {
        router.get(
            route("user-management.index"),
            {
                role_id: filterData.role_id,
                status: filterData.status,
                page,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ["users", "filters"],
            },
        );
    };

    const handleToggleStatus = (user) => {
        const isActive = user.status !== "inactive";

        Swal.fire({
            title: isActive ? "Deactivate Account?" : "Activate Account?",
            text: isActive
                ? `Deactivate "${user.name}"? They will no longer be able to log in.`
                : `Reactivate "${user.name}"? They will be able to log in again.`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: isActive ? "#dc2626" : "#16a34a",
            cancelButtonColor: "#6b7280",
            confirmButtonText: isActive ? "Deactivate" : "Activate",
        }).then((result) => {
            if (result.isConfirmed) {
                patch(route("user-management.toggle-status", user.id), {
                    preserveScroll: true,
                    only: ["users", "flash"],
                });
            }
        });
    };

    const hasActiveFilters = filterData.role_id || filterData.status;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Users</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div className="flex-1">
                        <InputLabel htmlFor="filter_role" value="Filter by Role" />
                        <select
                            id="filter_role"
                            value={filterData.role_id}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            onChange={(e) => {
                                const role_id = e.target.value;
                                setFilterData("role_id", role_id);
                                applyFilters({ role_id });
                            }}
                        >
                            <option value="">All Roles</option>
                            {availableRoles.map((role) => (
                                <option key={role.id} value={role.id}>
                                    {role.role_name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex-1">
                        <InputLabel htmlFor="filter_status" value="Filter by Status" />
                        <select
                            id="filter_status"
                            value={filterData.status}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            onChange={(e) => {
                                const status = e.target.value;
                                setFilterData("status", status);
                                applyFilters({ status });
                            }}
                        >
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Deactivated</option>
                        </select>
                    </div>

                    {hasActiveFilters && (
                        <Button type="button" variant="outline" onClick={clearFilters}>
                            Clear Filters
                        </Button>
                    )}
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {visibleUsers.length ? (
                                visibleUsers.map((user) => {
                                    const isActive = user.status !== "inactive";
                                    const isCurrentUser =
                                        user.id === currentUserId;

                                    return (
                                        <TableRow key={user.id}>
                                            <TableCell>{user.name}</TableCell>
                                            <TableCell>{user.email}</TableCell>
                                            <TableCell>
                                                {user.role?.role_name || "-"}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={
                                                        user.status || "active"
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <EditModal
                                                        user={user}
                                                        roles={roles}
                                                    >
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            className="flex items-center gap-1"
                                                        >
                                                            <Pencil className="h-3.5 w-3.5" />
                                                            Edit
                                                        </Button>
                                                    </EditModal>
                                                    {!isCurrentUser && (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            className={`flex items-center gap-1 ${
                                                                isActive
                                                                    ? "text-red-600 hover:text-red-700"
                                                                    : "text-green-700 hover:text-green-800"
                                                            }`}
                                                            onClick={() =>
                                                                handleToggleStatus(
                                                                    user,
                                                                )
                                                            }
                                                        >
                                                            {isActive ? (
                                                                <>
                                                                    <UserX className="h-3.5 w-3.5" />
                                                                    Deactivate
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <UserCheck className="h-3.5 w-3.5" />
                                                                    Activate
                                                                </>
                                                            )}
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={5} className="h-24 text-center">
                                        No users found.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-gray-600">
                        Showing {users.from ?? 0} to {users.to ?? 0} of{" "}
                        {users.total ?? 0} users
                    </p>
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => goToPage(users.current_page - 1)}
                            disabled={users.current_page <= 1}
                        >
                            Previous
                        </Button>
                        <span className="text-sm text-gray-600">
                            Page {users.current_page} of {users.last_page}
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => goToPage(users.current_page + 1)}
                            disabled={users.current_page >= users.last_page}
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
