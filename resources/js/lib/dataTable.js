import { useEffect } from "react";
import { flexRender } from "@tanstack/react-table";

/**
 * Render a column's `header` / `cell` definition.
 *
 * flexRender() mounts a function definition as a React component. Pages
 * rebuild their column definitions on every render (getColumns(...) with
 * page props), so each render hands React a brand-new component type and
 * the whole cell remounts. A row-action dialog living in that cell (e.g.
 * Set Required Hours, Edit student) then closes and loses its form state
 * and validation errors whenever an Inertia 422 response re-renders the
 * page. Calling the function directly keeps the returned elements' own
 * (module-level) component types, so React reconciles them in place.
 *
 * Cell/header functions must therefore not call hooks; put stateful UI in
 * a real component and return it from the function.
 */
export function renderTableContent(content, context) {
    return typeof content === "function"
        ? content(context)
        : flexRender(content, context);
}

/**
 * Stable row ids (the record's own id) so a row keeps its React key — and
 * any open dialog in it — when the data array is rebuilt from fresh props.
 */
export function getStableRowId(row, index) {
    return row?.id != null ? String(row.id) : String(index);
}

/**
 * Options shared by the page DataTables: stable row ids, and no automatic
 * jump back to page 1 when `data` changes (a 422 re-render rebuilds the
 * array, which would otherwise unmount a row on a later page).
 */
export const STABLE_TABLE_OPTIONS = {
    getRowId: getStableRowId,
    autoResetPageIndex: false,
};

/**
 * With autoResetPageIndex off, keep the page in range when the data or a
 * filter shrinks the row count.
 */
export function useClampPageIndex(table) {
    const pageCount = table.getPageCount();
    const { pageIndex } = table.getState().pagination;

    useEffect(() => {
        if (pageIndex > 0 && pageIndex >= pageCount) {
            table.setPageIndex(Math.max(pageCount - 1, 0));
        }
    }, [pageIndex, pageCount]);
}
