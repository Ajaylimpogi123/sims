<?php

namespace App\Support;

/**
 * The mobile app's screen keys (its FeatureKeys) and the website page each
 * one corresponds to. Shared by every place that tells a viewer where to go
 * next — notification links/targets (NotificationService) and dashboard
 * action items (DashboardAnalyticsService) — so the website link and the
 * app `target` always point at the same page.
 *
 * The keys are part of the mobile API contract (docs/api/v1.md): never
 * rename or remove one, only add. Callers decide which screen a role may be
 * sent to; this class only maps a key to its web route.
 */
final class AppScreen
{
    public const ROUTES = [
        'approvals' => 'attendance-approvals.index',
        'attendance-monitoring' => 'attendance-monitoring.index',
        'attendance' => 'attendance.index',
        'report-reviews' => 'report-reviews.index',
        'my-reports' => 'reports.index',
        'home' => 'dashboard',
        'progress' => 'progress-monitoring.index',
        'students' => 'internship-assignment.index',
        'evaluations' => 'supervisor-evaluations.index',
    ];

    /**
     * Website URL of a screen.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function url(string $screen, array $parameters = []): string
    {
        return route(self::ROUTES[$screen], $parameters);
    }

    /**
     * The app-side `target` object: `params` is always a JSON object, `{}`
     * when empty (an empty PHP array would otherwise encode as `[]`).
     *
     * @param  array<string, int>  $params
     * @return array{screen: string, params: object}
     */
    public static function target(string $screen, array $params = []): array
    {
        return ['screen' => $screen, 'params' => (object) $params];
    }
}
