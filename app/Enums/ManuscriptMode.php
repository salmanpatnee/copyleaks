<?php

namespace App\Enums;

/** How the manuscript pages of a report were made (spec 0007). */
enum ManuscriptMode: string
{
    /** The author's own PDF pages. */
    case Original = 'original';

    /** Simple text pages built from the Copyleaks text. */
    case Text = 'text';
}
