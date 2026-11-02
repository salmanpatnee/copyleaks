<?php

namespace App\Enums;

/** The look of a match group: color, icon and description. Which match belongs where is decided elsewhere. */
enum MatchGroupStyle: string
{
    case NotCitedOrQuoted = 'not_cited_or_quoted';
    case MissingQuotations = 'missing_quotations';
    case MissingCitation = 'missing_citation';
    case CitedAndQuoted = 'cited_and_quoted';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::NotCitedOrQuoted => 'Not Cited or Quoted',
            self::MissingQuotations => 'Missing Quotations',
            self::MissingCitation => 'Missing Citation',
            self::CitedAndQuoted => 'Cited and Quoted',
            self::Ai => 'AI-generated',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NotCitedOrQuoted => 'Matches with neither in-text citation nor quotation marks',
            self::MissingQuotations => 'Matches that are still very similar to source material',
            self::MissingCitation => 'Matches that have quotation marks, but no in-text citation',
            self::CitedAndQuoted => 'Matches with in-text citation present, but no quotation marks',
            self::Ai => 'Likely generated or likely generated and revised by AI.',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotCitedOrQuoted => '#f86371',
            self::MissingQuotations => '#f68e4a',
            self::MissingCitation => '#f4e4aa',
            self::CitedAndQuoted => '#c1ecdf',
            self::Ai => '#52c7db',
        };
    }

    /**
     * Size of the icon in points and its offset inside the circle (measured from the Turnitin samples).
     * The AI icon is a whole circle with the robot in it, so it has no separate circle.
     *
     * @return array{w: float, h: float, dx: float, dy: float, circle: bool}
     */
    public function iconBox(): array
    {
        return match ($this) {
            self::NotCitedOrQuoted => ['w' => 5.5, 'h' => 6.0, 'dx' => 3.0, 'dy' => 3.0, 'circle' => true],
            self::MissingQuotations => ['w' => 5.5, 'h' => 3.5, 'dx' => 4.0, 'dy' => 4.0, 'circle' => true],
            self::MissingCitation => ['w' => 6.0, 'h' => 4.0, 'dx' => 3.0, 'dy' => 4.0, 'circle' => true],
            self::CitedAndQuoted => ['w' => 5.5, 'h' => 6.0, 'dx' => 3.0, 'dy' => 3.0, 'circle' => true],
            self::Ai => ['w' => 12.0, 'h' => 12.0, 'dx' => 0.0, 'dy' => 1.5, 'circle' => false],
        };
    }

    /** File name of the icon inside the circle (without folder). */
    public function icon(): string
    {
        return match ($this) {
            self::NotCitedOrQuoted => 'group-copy.png',
            self::MissingQuotations => 'group-quote.png',
            self::MissingCitation => 'group-lines.png',
            self::CitedAndQuoted => 'group-cap.png',
            self::Ai => 'group-ai.png',
        };
    }
}
