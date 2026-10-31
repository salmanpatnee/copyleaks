<?php

namespace App\Enums;

/** The two file types the upload screen accepts. Decided from the file bytes. */
enum ManuscriptType: string
{
    case Pdf = 'pdf';
    case Docx = 'docx';
}
