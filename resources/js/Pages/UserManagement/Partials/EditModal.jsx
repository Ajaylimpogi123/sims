import InputError from "@/Components/InputError";
import InputLabel from "@/Components/InputLabel";
import TextInput from "@/Components/TextInput";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogClose,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from "@/components/ui/dialog";
import useEditUser from "../Hooks/useEditUser";

export default function EditModal({ user, roles, children }) {
    // The Administrator role is already excluded from `roles` server-side
    // (UserController::index) whenever the viewer isn't an Administrator
    // themselves, so no client-side filtering is needed here.
    const {
        open,
        openModal,
        closeModal,
        data,
        setData,
        errors,
        processing,
        handleSubmit,
    } = useEditUser(user);

    return (
        <>
            <div onClick={openModal}>{children}</div>

            <Dialog open={open} onOpenChange={closeModal}>
                <DialogContent className="sm:max-w-[500px]">
                    <form onSubmit={handleSubmit}>
                        <DialogHeader>
                            <DialogTitle>Edit User</DialogTitle>
                            <DialogDescription>
                                Update account details for {user.name}
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <div>
                                <InputLabel htmlFor="edit_name" value="Name" />
                                <TextInput
                                    id="edit_name"
                                    value={data.name}
                                    className="mt-1 block w-full"
                                    onChange={(e) =>
                                        setData("name", e.target.value)
                                    }
                                    required
                                />
                                <InputError
                                    message={errors.name}
                                    className="mt-2"
                                />
                            </div>

                            <div>
                                <InputLabel htmlFor="edit_email" value="Email" />
                                <TextInput
                                    id="edit_email"
                                    type="email"
                                    value={data.email}
                                    className="mt-1 block w-full"
                                    onChange={(e) =>
                                        setData("email", e.target.value)
                                    }
                                    required
                                />
                                <InputError
                                    message={errors.email}
                                    className="mt-2"
                                />
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="edit_role_id"
                                    value="Role"
                                />
                                <select
                                    id="edit_role_id"
                                    value={data.role_id}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    onChange={(e) =>
                                        setData("role_id", e.target.value)
                                    }
                                    required
                                >
                                    <option value="">- Select a Role -</option>
                                    {roles.map((role) => (
                                        <option key={role.id} value={role.id}>
                                            {role.role_name}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={errors.role_id}
                                    className="mt-2"
                                />
                            </div>

                            <div>
                                <InputLabel
                                    htmlFor="edit_password"
                                    value="New Password"
                                />
                                <TextInput
                                    id="edit_password"
                                    type="password"
                                    value={data.password}
                                    className="mt-1 block w-full"
                                    autoComplete="new-password"
                                    onChange={(e) =>
                                        setData("password", e.target.value)
                                    }
                                />
                                <p className="mt-1 text-xs text-gray-500">
                                    Leave blank to keep the current password
                                </p>
                                <InputError
                                    message={errors.password}
                                    className="mt-2"
                                />
                            </div>

                            {data.password && (
                                <div>
                                    <InputLabel
                                        htmlFor="edit_password_confirmation"
                                        value="Confirm New Password"
                                    />
                                    <TextInput
                                        id="edit_password_confirmation"
                                        type="password"
                                        value={data.password_confirmation}
                                        className="mt-1 block w-full"
                                        autoComplete="new-password"
                                        onChange={(e) =>
                                            setData(
                                                "password_confirmation",
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                        className="mt-2"
                                    />
                                </div>
                            )}
                        </div>

                        <DialogFooter>
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={processing}
                                    onClick={closeModal}
                                >
                                    Cancel
                                </Button>
                            </DialogClose>

                            <Button type="submit" disabled={processing}>
                                Save Changes
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
