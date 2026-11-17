<?php

namespace App\Services\Identity;

use App\Services\GeminiClient;

/** Reads an ID photo with Gemini through the existing GeminiClient (same request shape as DocumentChecker). */
class GeminiIdReader implements IdDocumentReader
{
    public function __construct(private GeminiClient $gemini) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function read(string $mimeType, string $bytes): array
    {
        $types = implode('|', array_keys(PhIdRules::TYPES));
        $prompt = <<<PROMPT
You read Philippine government ID cards for a customs brokerage's client identity check.
Look at the image and respond with ONLY valid JSON:
{"id_type": "{$types}|other|not_an_id",
 "full_name": "<name exactly as printed>", "last_name": "", "first_name": "", "middle_name": "",
 "birth_date": "YYYY-MM-DD or null", "id_number": "<the main ID / card number exactly as printed, or null>",
 "expiry_date": "YYYY-MM-DD or null if the card has no expiry", "has_face_photo": true|false,
 "quality_issues": ["any of: blurry, glare, cropped, too_dark, too_small"],
 "tamper_signs": ["any of: photo_of_screen, photocopy, edited_text, mismatched_fonts, sticker_over_text"],
 "confidence": <0.0-1.0, how sure you are that every field above was read correctly>}
Rules: philsys = PhilID card or ePhilID printout; drivers_license = LTO license; passport = DFA passport.
Copy text and numbers exactly as printed. Use null for anything not visible. Never guess or complete missing digits.
PROMPT;

        $result = $this->gemini->generateJson($mimeType, $bytes, $prompt);
        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }

        return ['data' => $result['data']];
    }
}
