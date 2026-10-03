<?php

namespace App\Http\Resources;

use App\Services\InternshipReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * One internship report as the mobile app sees it. `can_edit` / `can_delete`
 * come from InternshipReportPolicy for the requesting user, and the
 * attachment URL points at the API download endpoint, which re-checks
 * InternshipReportPolicy::view on every request.
 *
 * Load `reviewer:id,name` to avoid a query per report.
 *
 * @mixin \App\Models\InternshipReport
 */
class InternshipReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'period_start' => $this->period_start?->format('Y-m-d'),
            'period_end' => $this->period_end?->format('Y-m-d'),
            'content' => $this->content,
            'status' => $this->status,
            'reviewer_comment' => $this->reviewer_comment,
            'reviewer' => $this->reviewer ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name] : null,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'attachment' => $this->attachment(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can_edit' => (bool) $user?->can('update', $this->resource),
            'can_delete' => (bool) $user?->can('delete', $this->resource),
        ];
    }

    /**
     * `null` when there is no attachment, or its file is missing from disk.
     * The URL carries a short fingerprint of the stored file, so it changes
     * when the attachment is replaced and the app can cache by URL.
     */
    private function attachment(): ?array
    {
        $path = $this->attachment_path;
        $disk = Storage::disk(InternshipReportService::ATTACHMENT_DISK);

        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        $mime = $disk->mimeType($path) ?: 'application/octet-stream';

        return [
            'name' => $this->attachment_original_name ?: basename($path),
            'mime' => $mime,
            'kind' => match (true) {
                str_starts_with($mime, 'image/') => 'image',
                $mime === 'application/pdf' => 'pdf',
                default => 'file',
            },
            'size' => (int) $disk->size($path),
            'url' => route('api.v1.reports.attachment', [
                'report' => $this->id,
                'v' => substr(sha1($path), 0, 12),
            ]),
        ];
    }
}
