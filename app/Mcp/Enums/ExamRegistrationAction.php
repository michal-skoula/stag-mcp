<?php

namespace App\Mcp\Enums;

/**
 * The four changes STAG allows a student to make on an exam term, each its
 * own `terminy` endpoint. Every one of them is an HTTP GET that changes data
 * on the first request, so nothing outside ExamRegistrar may call them.
 */
enum ExamRegistrationAction: string
{
    case Register = 'register';
    case Unregister = 'unregister';
    case JoinQueue = 'join_queue';
    case LeaveQueue = 'leave_queue';

    /**
     * The STAG endpoint that performs this action.
     */
    public function endpoint(): string
    {
        return match ($this) {
            self::Register => 'terminy/zapisStudentaNaTermin',
            self::Unregister => 'terminy/odhlasStudentaZTerminu',
            self::JoinQueue => 'terminy/zapisStudentaDoFronty',
            self::LeaveQueue => 'terminy/odhlasStudentaZFronty',
        };
    }

    public function isQueue(): bool
    {
        return $this === self::JoinQueue || $this === self::LeaveQueue;
    }

    /**
     * Phrase for messages, e.g. "register for".
     */
    public function verb(): string
    {
        return match ($this) {
            self::Register => 'register for',
            self::Unregister => 'unregister from',
            self::JoinQueue => 'join the waiting queue for',
            self::LeaveQueue => 'leave the waiting queue for',
        };
    }
}
