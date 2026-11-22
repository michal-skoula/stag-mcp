<?php

namespace App\Mcp\Enums;

/**
 * STAG's `PLATNOST_TERMINU` číselník, the `platnost` of one exam term.
 *
 * Sourced from `ciselniky/getCiselnik?domena=PLATNOST_TERMINU`. `B` is not a
 * blocked term but a room booking a department stores as a term (state exam
 * days, summer schools, conferences), so it is never an exam anyone can sit.
 */
enum ExamTermStatus: string
{
    case Valid = 'A';
    case Cancelled = 'N';
    case RoomBooking = 'B';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'valid',
            self::Cancelled => 'cancelled',
            self::RoomBooking => 'room_booking',
        };
    }
}
