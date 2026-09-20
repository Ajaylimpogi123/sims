import { jsPDF } from "jspdf";
import { autoTable } from "jspdf-autotable";
import * as XLSX from "xlsx";

/**
 * Format a single cell value for export. Keeps numbers/strings as-is,
 * renders null/undefined as an empty string so exports don't show
 * "null"/"undefined" literally.
 */
function formatCell(value) {
    if (value === null || value === undefined) {
        return "";
    }

    return value;
}

/**
 * Build the plain [{header: value}] rows shared by both export formats.
 *
 * @param {{ header: string, accessor: string }[]} columns
 * @param {Object[]} rows
 */
function toExportRows(columns, rows) {
    return rows.map((row) =>
        columns.map((column) => formatCell(row[column.accessor])),
    );
}

/**
 * Export tabular data to a downloaded PDF file.
 *
 * @param {Object} params
 * @param {{ header: string, accessor: string }[]} params.columns
 * @param {Object[]} params.rows
 * @param {string} params.filename - without extension
 * @param {string} [params.title] - optional heading printed above the table
 */
export function exportToPdf({ columns, rows, filename, title }) {
    const doc = new jsPDF();

    if (title) {
        doc.setFontSize(14);
        doc.text(title, 14, 15);
    }

    autoTable(doc, {
        startY: title ? 22 : 10,
        head: [columns.map((column) => column.header)],
        body: toExportRows(columns, rows),
        styles: { fontSize: 9 },
        headStyles: { fillColor: [16, 122, 87] },
    });

    doc.save(`${filename}.pdf`);
}

/**
 * Export tabular data to a downloaded Excel (.xlsx) file.
 *
 * @param {Object} params
 * @param {{ header: string, accessor: string }[]} params.columns
 * @param {Object[]} params.rows
 * @param {string} params.filename - without extension
 * @param {string} [params.sheetName]
 */
export function exportToExcel({ columns, rows, filename, sheetName = "Sheet1" }) {
    const headerRow = columns.map((column) => column.header);
    const bodyRows = toExportRows(columns, rows);

    const worksheet = XLSX.utils.aoa_to_sheet([headerRow, ...bodyRows]);
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, sheetName);

    XLSX.writeFile(workbook, `${filename}.xlsx`);
}
