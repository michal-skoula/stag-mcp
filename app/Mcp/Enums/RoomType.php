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
}
