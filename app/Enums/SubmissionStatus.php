<?php

namespace App\Enums;

/** Worked out from the latest scan, never stored (AC-10). */
enum SubmissionStatus: string
{
    case NotSent = 'not_sent';
    case Waiting = 'waiting';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NotSent => 'Not sent',
            self::Waiting => 'Waiting',
            self::Done => 'Done',
            self::Failed => 'Failed',
        };
    }

    public static function fromScan(?ScanStatus $scan): self
    {
        return match ($scan) {
            null => self::NotSent,
            ScanStatus::Submitting, ScanStatus::Waiting, ScanStatus::Fetching => self::Waiting,
            ScanStatus::Completed => self::Done,
            ScanStatus::Failed => self::Failed,
        };
    }
}
