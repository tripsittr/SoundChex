<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\ActivityLog;

use App\Models\AuditLogEntry;
use App\Models\MediaItem;
use App\Models\Notification;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Turns any observed event into one audit-log row (S-284).
 *
 * The event catalogue is large and its payloads are heterogeneous — some carry a
 * MediaItem, some a Profile, some a profile id, some an array of counts. Rather
 * than hand-code extraction for forty event classes and break every time one is
 * added, this reflects over the event object's public readonly properties and
 * pulls out what it recognises: an item becomes the subject, a profile (or a
 * profile id) becomes the actor, and the remaining scalars become the context
 * bag. A new event is captured with no change here.
 *
 * Everything is best-effort and swallowed: an audit log must never be the thing
 * that fails the action it is recording.
 */
class AuditRecorder
{
    public function record(string $event, object $payload): void
    {
        try {
            $props = $this->readableProperties($payload);

            [$profileId, $actorName] = $this->resolveActor($props);
            [$subjectId, $subjectType, $subjectTitle] = $this->resolveSubject($props);

            AuditLogEntry::create([
                'event' => $event,
                'summary' => $this->summarise($event, $subjectTitle, $actorName),
                'profile_id' => $profileId,
                'actor_name' => $actorName,
                'subject_id' => $subjectId,
                'subject_type' => $subjectType,
                'subject_title' => $subjectTitle,
                'context' => $this->context($props),
            ]);
        } catch (\Throwable $e) {
            // Never let auditing break the thing being audited.
            Log::warning('Activity Log could not record an event', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The event object's public properties as a name => value map. Events are
     * plain readonly-property objects, so this is their whole payload.
     *
     * @return array<string, mixed>
     */
    private function readableProperties(object $payload): array
    {
        $out = [];

        foreach (get_object_vars($payload) as $name => $value) {
            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * The acting profile, as [id, name]. Prefers a Profile object; falls back to
     * a `profileId` scalar, resolving the name if the profile still exists.
     *
     * @param  array<string, mixed>  $props
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveActor(array $props): array
    {
        foreach ($props as $value) {
            if ($value instanceof Profile) {
                return [$value->id, $value->name];
            }
        }

        $id = $props['profileId'] ?? null;

        if (is_int($id)) {
            $name = Profile::query()->whereKey($id)->value('name');

            return [$id, is_string($name) ? $name : null];
        }

        return [null, null];
    }

    /**
     * The subject of the event, as [id, type, title]. A MediaItem is the common
     * one; a Notification and other named models are captured generically.
     *
     * @param  array<string, mixed>  $props
     * @return array{0: ?int, 1: ?string, 2: ?string}
     */
    private function resolveSubject(array $props): array
    {
        foreach ($props as $value) {
            if ($value instanceof MediaItem) {
                return [$value->id, 'media_item', $value->title];
            }
        }

        foreach ($props as $value) {
            if ($value instanceof Notification) {
                return [$value->id, 'notification', $value->title ?? null];
            }
        }

        // A model we do not special-case: record its id and a short type name so
        // the row still points at something, without assuming a title column.
        foreach ($props as $value) {
            if ($value instanceof Model) {
                return [$value->getKey(), class_basename($value), null];
            }
        }

        return [null, null, null];
    }

    /**
     * The scalar payload, for the detail view and filtering. Objects are reduced
     * to a short label rather than serialised whole — the row records what
     * happened, not a copy of every model involved.
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function context(array $props): array
    {
        $context = [];

        foreach ($props as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $context[$name] = $value;
            } elseif (is_array($value)) {
                // Keep only the scalar leaves — a scan result's counts, not a
                // nested object graph.
                $context[$name] = array_filter(
                    $value,
                    fn ($v): bool => is_scalar($v) || $v === null,
                );
            } elseif ($value instanceof Model) {
                $context[$name] = class_basename($value).'#'.$value->getKey();
            }
        }

        return $context;
    }

    /**
     * A short human line for the timeline, built once at write time.
     */
    private function summarise(string $event, ?string $subjectTitle, ?string $actorName): string
    {
        // "playback.completed" → "Playback completed"
        $action = ucfirst(str_replace(['.', '_'], ' ', $event));

        $line = $action;

        if ($subjectTitle !== null && $subjectTitle !== '') {
            $line .= ' — '.$subjectTitle;
        }

        if ($actorName !== null && $actorName !== '') {
            $line .= ' (by '.$actorName.')';
        }

        return $line;
    }
}
