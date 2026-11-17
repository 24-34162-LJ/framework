<?php

namespace App\Services\Identity;

/**
 * Reads the printed fields from a photo of a Philippine government ID.
 * Implementations only extract text; PhIdRules decides whether the ID passes.
 * Swap the implementation with services.identity.reader (Gemini today, Claude later).
 */
interface IdDocumentReader
{
    /** Short name stored with each verification, e.g. "gemini". */
    public function name(): string;

    /**
     * Data keys: id_type, full_name, last_name, first_name, middle_name, birth_date, id_number,
     * expiry_date, has_face_photo, quality_issues[], tamper_signs[], confidence.
     *
     * @return array{data?: array<string, mixed>, error?: string}
     */
    public function read(string $mimeType, string $bytes): array;
}
