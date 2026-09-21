import InputError from "@/Components/InputError";
import InputLabel from "@/Components/InputLabel";
import PrimaryButton from "@/Components/PrimaryButton";
import TextInput from "@/Components/TextInput";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import { useForm } from "@inertiajs/react";
import { showRegistrationError } from "../Hooks/useRegistrationAlerts";

export default function RegistrationForm({ roles }) {
    // The Administrator role is already excluded from `roles` server-side
    // (UserController::index) whenever the viewer isn't an Administrator
    // themselves, so no client-side filtering is needed here.
    const { data, setData, post, processing, errors, reset } = useForm({
        name: "",
        email: "",
        password: "",
        password_confirmation: "",
        role_id: "",
    });

    const submit = (e) => {
        e.preventDefault();

        post(route("user-management.store"), {
            onError: (formErrors) => {
                showRegistrationError(formErrors);
            },
            onFinish: () => reset("password", "password_confirmation"),
            preserveScroll: true,
        });
    };

    return (
        <Card id="user-registration">
            <CardHeader>
                <CardTitle>User Registration</CardTitle>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="max-w-xl">
                    <div>
                        <InputLabel htmlFor="name" value="Name" />
                        <TextInput
                            id="name"
                            name="name"
                            value={data.name}
                            className="mt-1 block w-full"
                            autoComplete="name"
                            onChange={(e) => setData("name", e.target.value)}
                            required
                        />
                        <InputError message={errors.name} className="mt-2" />
                    </div>

                    <div className="mt-4">
                        <InputLabel htmlFor="email" value="Email" />
                        <TextInput
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="mt-1 block w-full"
                            autoComplete="username"
                            onChange={(e) => setData("email", e.target.value)}
                            required
                        />
                        <InputError message={errors.email} className="mt-2" />
                    </div>

                    <div className="mt-4">
                        <InputLabel htmlFor="role_id" value="Role" />
                        <select
                            id="role_id"
                            name="role_id"
                            value={data.role_id}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            onChange={(e) => setData("role_id", e.target.value)}
                            required
                        >
                            <option value="">- Select a Role -</option>
                            {roles.map((role) => (
                                <option key={role.id} value={role.id}>
                                    {role.role_name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.role_id} className="mt-2" />
                    </div>

                    <div className="mt-4">
                        <InputLabel htmlFor="password" value="Password" />
                        <TextInput
                            id="password"
                            type="password"
                            name="password"
                            value={data.password}
                            className="mt-1 block w-full"
                            autoComplete="new-password"
                            onChange={(e) =>
                                setData("password", e.target.value)
                            }
                            required
                        />
                        <InputError
                            message={errors.password}
                            className="mt-2"
                        />
                    </div>

                    <div className="mt-4">
                        <InputLabel
                            htmlFor="password_confirmation"
                            value="Confirm Password"
                        />
                        <TextInput
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            value={data.password_confirmation}
                            className="mt-1 block w-full"
                            autoComplete="new-password"
                            onChange={(e) =>
                                setData("password_confirmation", e.target.value)
                            }
                            required
                        />
                        <InputError
                            message={errors.password_confirmation}
                            className="mt-2"
                        />
                    </div>

                    <div className="mt-6 flex justify-end">
                        <PrimaryButton disabled={processing}>
                            Register User
                        </PrimaryButton>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}
