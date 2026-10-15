import { AlertTriangle } from "lucide-react";

// Students may only submit attendance and reports while their internship is
// "ongoing" (enforced server-side; see the `submission` prop on
// Attendance/Index and InternshipReports/Index, `student.can_submit` on the
// dashboard). This only explains why the actions are disabled.
export function submissionClosedMessage(internshipStatus) {
    if (internshipStatus === "ongoing") return null;

    if (internshipStatus === "completed") {
        return "Your internship is Completed — attendance and reports are closed.";
    }

    return "Your internship is Not Started — you can submit attendance and reports once it is Ongoing.";
}

export default function SubmissionClosedNotice({ internshipStatus, reason }) {
    const message = submissionClosedMessage(internshipStatus) ?? reason;
    if (!message) return null;

    return (
        <div
            role="status"
            className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
        >
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
            <p>{message}</p>
        </div>
    );
}
