import { router, usePage } from "@inertiajs/react";

const ADMIN_ROLE_ID = 4;
const STUDENT_ROLE_ID = 1;

/**
 * The school term the user is viewing, from the shared Inertia props:
 *   terms        [{ id, label, is_current }] (newest first)
 *   currentTerm  { id, label } | null
 *   selectedTerm { id, label, is_current } | null (students: their own term)
 *   termArchived bool (selected term isn't the current one)
 *   termReadOnly bool (termArchived && the user isn't an Administrator)
 *
 * `canWrite` mirrors the server's TermGuard: archived terms are read-only
 * except for Administrators. This only hides buttons — the server refuses
 * archived writes on its own (validation error on `term`).
 */
export default function useTerm() {
    const {
        terms,
        currentTerm,
        selectedTerm,
        termArchived,
        termReadOnly,
        auth,
    } = usePage().props;
    const roleId = auth?.user?.role_id;
    const archived = Boolean(termArchived);

    const selectTerm = (termId) => {
        if (termId == null) return;

        router.post(
            route("term.select"),
            { term_id: termId },
            { preserveScroll: true },
        );
    };

    return {
        terms: terms ?? [],
        currentTerm: currentTerm ?? null,
        selectedTerm: selectedTerm ?? null,
        termArchived: archived,
        isStudent: roleId === STUDENT_ROLE_ID,
        canWrite:
            termReadOnly != null
                ? !termReadOnly
                : !archived || roleId === ADMIN_ROLE_ID,
        selectTerm,
    };
}
