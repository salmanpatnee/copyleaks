<?php

return [
    /** Our logo (PNG, 1392 x 417 px). Keep it out of public/. */
    'logo' => resource_path('report/logo.png'),

    /** Static TTF files and their OFL texts. */
    'fonts_dir' => resource_path('report/fonts'),

    /** Small icons (8 pixels per point). */
    'icons_dir' => resource_path('report/icons'),

    /** mPDF font cache and scratch files. */
    'temp_dir' => storage_path('app/mpdf'),

    /** Samples keep only this many newest files. */
    'sample_keep' => 5,
];
