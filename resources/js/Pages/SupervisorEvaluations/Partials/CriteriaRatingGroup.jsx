import { Label } from "@/components/ui/label";
import { groupByCategory } from "../lib/responses";

const RATING_SCALE = [1, 2, 3, 4, 5];

/**
 * Renders every active criterion grouped by category, with a 1-5 rating
 * picker + optional comment per criterion. Works in both editable and
 * read-only mode so it can back the create/edit form and the read-only
 * Show/MyFeedback views.
 */
export default function CriteriaRatingGroup({
    criteria,
    responses,
    onChange,
    readOnly = false,
    errors = {},
}) {
    const grouped = groupByCategory(criteria);

    if (!criteria.length) {
        return (
            <p className="text-sm text-muted-foreground">
                No evaluation criteria have been configured yet.
            </p>
        );
    }

    return (
        <div className="space-y-6">
            {Object.entries(grouped).map(([category, categoryCriteria]) => (
                <div key={category} className="space-y-3">
                    <h3 className="text-sm font-semibold text-foreground">
                        {category}
                    </h3>

                    <div className="space-y-4 rounded-md border p-4">
                        {categoryCriteria.map((criterion) => {
                            const value = responses[criterion.id] || {
                                rating: "",
                                comment: "",
                            };

                            return (
                                <div
                                    key={criterion.id}
                                    className="grid gap-2 border-b pb-4 last:border-b-0 last:pb-0"
                                >
                                    <div>
                                        <Label>{criterion.label}</Label>
                                        {criterion.description && (
                                            <p className="text-xs text-muted-foreground">
                                                {criterion.description}
                                            </p>
                                        )}
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2">
                                        {RATING_SCALE.map((score) => (
                                            <button
                                                type="button"
                                                key={score}
                                                disabled={readOnly}
                                                onClick={() =>
                                                    onChange?.(criterion.id, {
                                                        ...value,
                                                        rating: score,
                                                    })
                                                }
                                                className={`flex h-8 w-8 items-center justify-center rounded-full border text-sm font-medium transition-colors ${
                                                    Number(value.rating) ===
                                                    score
                                                        ? "border-primary bg-primary text-primary-foreground"
                                                        : "border-input bg-transparent"
                                                } ${readOnly ? "cursor-default opacity-80" : "cursor-pointer hover:bg-muted"}`}
                                            >
                                                {score}
                                            </button>
                                        ))}
                                        {!value.rating && (
                                            <span className="text-xs text-muted-foreground">
                                                Not rated
                                            </span>
                                        )}
                                    </div>

                                    {readOnly ? (
                                        value.comment && (
                                            <p className="rounded-md bg-muted/30 p-2 text-sm whitespace-pre-wrap">
                                                {value.comment}
                                            </p>
                                        )
                                    ) : (
                                        <textarea
                                            rows={2}
                                            placeholder="Comment (optional)"
                                            value={value.comment}
                                            onChange={(e) =>
                                                onChange?.(criterion.id, {
                                                    ...value,
                                                    comment: e.target.value,
                                                })
                                            }
                                            className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            ))}

            {errors.responses && (
                <p className="text-sm text-destructive">{errors.responses}</p>
            )}
        </div>
    );
}
