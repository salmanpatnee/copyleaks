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

    /** Front part of the printed Submission ID. Changing it relabels every report (spec 0012, AC-4). */
    'id_prefix' => env('REPORT_ID_PREFIX', 'trn:oid:::'),

    /** Fixed middle number of the printed Submission ID. */
    'id_account' => env('REPORT_ID_ACCOUNT', '3117'),

    /** Samples keep only this many newest files. */
    'sample_keep' => 5,

    'manuscript' => [
        /** Share of Copyleaks letters and digits that must be found on the PDF pages. */
        'min_match_share' => 0.98,

        /** Share of each PDF page's letters and digits that must match. */
        'min_page_share' => 0.90,
    ],
];
