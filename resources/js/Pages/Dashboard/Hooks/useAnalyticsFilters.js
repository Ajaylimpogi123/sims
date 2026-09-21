import { router, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";

const EMPTY_FILTERS = {
    date_from: "",
    date_to: "",
    company_id: "",
    supervisor_id: "",
    student_id: "",
};

/**
 * Wraps the server-driven Inertia filter-submission pattern already used in
 * UserManagement/Partials/UsersTable.jsx: local state synced from the
 * `filters` prop, submitted via router.get() against the CURRENT dashboard
 * URL (works for both /dashboard and /admin-dashboard without needing to
 * know which one rendered this page), requesting only the `analytics`
 * deferred prop back.
 */
export default function useAnalyticsFilters(filters) {
    const page = usePage();
    const [data, setData] = useState({ ...EMPTY_FILTERS, ...filters });

    useEffect(() => {
        setData({ ...EMPTY_FILTERS, ...filters });
    }, [filters]);

    const currentPath = page.url.split("?")[0];

    const submit = (nextData) => {
        router.get(currentPath, nextData, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ["analytics"],
        });
    };

    const apply = (overrides = {}) => {
        const nextData = { ...data, ...overrides };
        setData(nextData);
        submit(nextData);
    };

    const clear = () => {
        setData(EMPTY_FILTERS);
        submit(EMPTY_FILTERS);
    };

    return { data, setData, apply, clear };
}
