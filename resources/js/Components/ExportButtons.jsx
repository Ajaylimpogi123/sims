import { Button } from "@/components/ui/button";
import { FileDown, FileSpreadsheet } from "lucide-react";
import { exportToExcel, exportToPdf } from "@/lib/exportUtils";

/**
 * Shared "Export PDF" / "Export Excel" button pair. Generates files
 * entirely client-side from data already available on the page — no
 * server round trip.
 *
 * @param {Object} props
 * @param {{ header: string, accessor: string }[]} props.columns
 * @param {Object[]} props.rows
 * @param {string} props.filename - without extension
 * @param {string} [props.title] - heading printed above the PDF table
 */
export default function ExportButtons({ columns, rows, filename, title }) {
    const disabled = !rows || rows.length === 0;

    return (
        <div className="flex items-center gap-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={disabled}
                onClick={() => exportToPdf({ columns, rows, filename, title })}
            >
                <FileDown className="mr-1.5 h-4 w-4" />
                Export PDF
            </Button>
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={disabled}
                onClick={() => exportToExcel({ columns, rows, filename })}
            >
                <FileSpreadsheet className="mr-1.5 h-4 w-4" />
                Export Excel
            </Button>
        </div>
    );
}
