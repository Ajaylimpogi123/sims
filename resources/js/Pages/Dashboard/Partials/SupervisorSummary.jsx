import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { Users, FileText } from "lucide-react";
import StatCard from "./StatCard";
import HoursProgressBar from "./HoursProgressBar";

const STATUS_LABELS = {
    not_started: "Not Started",
    ongoing: "Ongoing",
    completed: "Completed",
};

export default function SupervisorSummary({ counts, supervisedStudents }) {
    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatCard
                    title="Supervised Students"
                    value={counts.supervisedStudents}
                    subtitle="Assigned to you"
                    icon={Users}
                    iconColor="text-blue-600"
                    iconBg="bg-blue-50"
                />
                <StatCard
                    title="Pending Report Reviews"
                    value={counts.pendingReportReviews}
                    subtitle="Awaiting your review"
                    icon={FileText}
                    iconColor="text-amber-600"
                    iconBg="bg-amber-50"
                />
            </div>

            <Card className="border-0 shadow-sm">
                <CardHeader>
                    <CardTitle>Your Students</CardTitle>
                </CardHeader>
                <CardContent>
                    {supervisedStudents.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No students are assigned to you yet.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Progress</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {supervisedStudents.map((student) => (
                                    <TableRow key={student.id}>
                                        <TableCell>{student.name}</TableCell>
                                        <TableCell>
                                            {STATUS_LABELS[student.internship_status] ??
                                                "Not Started"}
                                        </TableCell>
                                        <TableCell>
                                            <HoursProgressBar
                                                rendered={student.rendered_hours}
                                                required={student.required_hours}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
