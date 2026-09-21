/**
 * Shared helpers for turning the `evaluation_criteria` list + (optionally)
 * an evaluation's existing `responses` into a controlled-input-friendly map
 * keyed by criteria id, and back into the array shape the backend expects.
 * Criteria are driven live from the evaluation_criteria table — never
 * hardcoded here — so the form automatically reflects whatever an admin has
 * configured.
 */
export function initResponses(criteria, existingResponses = []) {
    const byId = {};

    existingResponses.forEach((response) => {
        byId[response.evaluation_criteria_id] = {
            rating: response.rating ?? "",
            comment: response.comment ?? "",
        };
    });

    const map = {};
    criteria.forEach((criterion) => {
        map[criterion.id] = byId[criterion.id] || { rating: "", comment: "" };
    });

    return map;
}

export function toResponsesArray(responsesMap) {
    return Object.entries(responsesMap)
        .filter(([, value]) => value.rating !== "" && value.rating != null)
        .map(([criteriaId, value]) => ({
            evaluation_criteria_id: Number(criteriaId),
            rating: Number(value.rating),
            comment: value.comment || "",
        }));
}

export function groupByCategory(criteria) {
    const groups = {};

    criteria.forEach((criterion) => {
        if (!groups[criterion.category]) {
            groups[criterion.category] = [];
        }
        groups[criterion.category].push(criterion);
    });

    return groups;
}

export function computeAverageRating(responsesMap) {
    const ratings = Object.values(responsesMap)
        .map((value) => Number(value.rating))
        .filter((rating) => !Number.isNaN(rating) && rating > 0);

    if (!ratings.length) {
        return null;
    }

    return Math.round((ratings.reduce((sum, r) => sum + r, 0) / ratings.length) * 100) / 100;
}
