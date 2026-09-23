<?php

namespace App\Mcp\Enums;

enum RoomType: string
{
    case Auditorium = 'Aula';
    case Office = 'Kancelář';
    case Laboratory = 'Laboratoř';
    case LectureHall = 'Posluchárna';
    case Gym = 'Tělovýchova';
    case Classroom = 'Učebna';
    case Other = 'Jiná';

    /**
     * STAG's numeric code for the type, as carried by a room row's typCiselne.
     *
     * The mistnost endpoints filter on this code, not on the label they return
     * in typ, so a filter built from the label silently matches nothing.
     */
    public function code(): string
    {
        return match ($this) {
            self::Classroom => '2',
            self::Laboratory => '4',
            self::Other => '5',
            self::LectureHall => '6',
            self::Gym => '7',
            self::Auditorium => '8',
            self::Office => '9',
        };
    }
}
