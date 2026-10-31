<?php

namespace App\Enums;

enum ScanStatus: string
{
    case Submitting = 'submitting';
    case Waiting = 'waiting';
    case Fetching = 'fetching';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * The states this one may move to (the table in spec 0002).
     *
     * @return list<self>
     */
    public function nextStates(): array
    {
        return match ($this) {
            self::Submitting => [self::Waiting, self::Fetching, self::Failed],
            self::Waiting => [self::Fetching, self::Failed],
            self::Fetching => [self::Completed, self::Failed],
            self::Completed, self::Failed => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->nextStates(), true);
    }

    /**
     * The states a scan may be in right now to be allowed to move to $this.
     *
     * @return list<self>
     */
    public function allowedFrom(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $from): bool => $from->canMoveTo($this),
        ));
    }

    /** The words staff see on the saved page (AC-11). */
    public function label(): string
    {
        return match ($this) {
            self::Submitting => 'Sending',
            self::Waiting => 'Waiting for Copyleaks',
            self::Fetching => 'Result received',
            self::Completed => 'Done',
            self::Failed => 'Failed',
        };
    }

    /** The saved page refreshes itself only while the answer is still on its way (AC-11). */
    public function isPolling(): bool
    {
        return $this === self::Submitting || $this === self::Waiting;
    }

    public function isTerminal(): bool
    {
        return $this->nextStates() === [];
    }

    /** A scan that is still being worked on. */
    public function isRunning(): bool
    {
        return ! $this->isTerminal();
    }
}
