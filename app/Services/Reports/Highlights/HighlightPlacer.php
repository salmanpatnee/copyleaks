<?php

namespace App\Services\Reports\Highlights;

use App\Enums\MatchGroupStyle;
use App\Services\Reports\Manuscript\ManuscriptPages;
use App\Services\Reports\Mapping\HighlightRange;
use App\Services\Reports\Parts\Highlight;

/**
 * Saved match ranges to highlights and badges. All position work is `ManuscriptPages::lineBoxes()`
 * (spec 0007); this class only copies the boxes and picks the badge spots.
 */
final class HighlightPlacer
{
    public function __construct(private readonly BadgeSlotter $slotter) {}

    /** @param  list<HighlightRange>  $ranges */
    public function place(array $ranges, ManuscriptPages $pages, MatchGroupStyle $style): OverlayPlan
    {
        $highlights = [];
        $candidates = [];
        $unplaced = 0;

        foreach ($ranges as $range) {
            $boxes = $pages->lineBoxes($range->start, $range->length);
            if ($boxes === []) {
                $unplaced++;

                continue;
            }

            foreach ($boxes as $box) {
                $highlights[$box->page][] = new Highlight($box->x, $box->y, $box->width, $box->height, $style);
            }

            $candidates[] = new BadgeCandidate($boxes[0]->page, $boxes[0]->y, $range->start, $range->rank, $style);
        }

        $slots = $this->slotter->place($candidates);

        return new OverlayPlan($highlights, $slots['badges'], $unplaced, $slots['dropped']);
    }
}
