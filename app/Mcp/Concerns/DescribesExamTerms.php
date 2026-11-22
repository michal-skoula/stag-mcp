<?php

namespace App\Mcp\Concerns;

use App\Services\ExamTerms\ExamTerm;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The output schema of one exam term, as {@see ExamTerm::toArray()}
 * produces it. Shared by every tool that returns a term, so they describe it
 * the same way.
 */
trait DescribesExamTerms
{
    protected function examTermSchema(JsonSchema $schema): Type
    {
        $nullableString = fn () => $schema->anyOf([$schema->string()])->nullable();
        $nullableInt = fn () => $schema->anyOf([$schema->integer()])->nullable();
        $nullableBool = fn () => $schema->anyOf([$schema->boolean()])->nullable();

        return $schema->object([
            'term_id' => $schema->integer()->description('STAG termIdno.'),
            'subject' => $schema->object([
                'katedra' => $schema->string()->description('Department code.'),
                'zkratka' => $schema->string()->description('Subject code.'),
                'rok' => $schema->string()->description('Academic year, starting year.'),
                'semestr' => $schema->string()->description('ZS, LS, or ZL for subjects running both semesters.'),
            ]),
            'type' => $nullableString()->description('"exam", "credit" (zápočet) or "credit_before_exam", or null for a type STAG has not used before.'),
            'type_label' => $nullableString()->description('STAG\'s own Czech label, e.g. "Zkouška".'),
            'status' => $nullableString()->description('"valid" or "cancelled".'),
            'date' => $nullableString()->description('ISO date.'),
            'time' => $schema->object([
                'from' => $nullableString()->description('Start, HH:MM, Prague time.'),
                'to' => $nullableString()->description('End, HH:MM, Prague time.'),
            ]),
            'location' => $schema->object([
                'building' => $nullableString()->description('Building code, e.g. "UU".'),
                'room' => $nullableString()->description('Room number, e.g. "108".'),
            ]),
            'examiner' => $schema->object([
                'teacher_id' => $nullableInt()->description('ucitIdno; get-ucitel-info has contact details.'),
                'name' => $nullableString()->description('Name with titles.'),
            ]),
            'capacity' => $schema->object([
                'registered' => $nullableInt()->description('Students registered.'),
                'limit' => $nullableInt()->description('Maximum, or null when the term has no limit.'),
                'free' => $nullableInt()->description('Places left, or null when there is no limit.'),
            ]),
            'group' => $schema->anyOf([$schema->object([
                'id' => $schema->integer()->description('STAG grteIdno shared by the terms in the group.'),
                'registered' => $nullableInt()->description('Registered across the group.'),
                'limit' => $nullableInt()->description('Group limit.'),
                'parent_registered' => $nullableInt()->description('Registered across the parent group.'),
                'parent_limit' => $nullableInt()->description('Parent group limit.'),
            ])])->nullable()->description('Set when the term is one slot of a group sharing capacity, null otherwise.'),
            'attempts_allowed' => $nullableString()->description('Who may register: "all", "retakes_only", "first_attempt_only", "first_and_second_attempt", "second_attempt_only" or "third_attempt_only".'),
            'deadlines' => $schema->object([
                'registration_opens' => $nullableString()->description('When registration opens, "Y-m-d H:i" Prague time, or null when STAG does not say.'),
                'register_by' => $nullableString()->description('Registration closes, "Y-m-d H:i" Prague time. Often later than unregister_by.'),
                'register_by_passed' => $nullableBool()->description('Whether register_by is already past.'),
                'unregister_by' => $nullableString()->description('Unregistration closes, "Y-m-d H:i" Prague time.'),
                'unregister_by_passed' => $nullableBool()->description('Whether unregister_by is already past.'),
            ]),
            'contact' => $nullableString()->description('Contact note, rarely filled.'),
            'note' => $nullableString()->description('The examiner\'s free-text note: requirements, format, who the term is for.'),
            'registration' => $schema->anyOf([$schema->object([
                'registered' => $schema->boolean()->description('Whether the caller is registered (an excused registration counts).'),
                'can_change' => $nullableBool()->description('Whether STAG would let the caller register or unregister right now, or null when unknown.'),
                'reason_code' => $nullableString()->description('STAG\'s code for why not.'),
                'reason' => $nullableString()->description('STAG\'s reason why not.'),
                'reason_detail' => $nullableString()->description('STAG\'s longer explanation.'),
                'queue' => $schema->object([
                    'registered' => $nullableBool()->description('Whether the caller is in the waiting queue.'),
                    'can_change' => $nullableBool()->description('Whether the caller may join or leave the queue right now.'),
                    'reason' => $nullableString()->description('STAG\'s reason why not.'),
                ]),
            ])])->nullable()->description('The caller\'s state on this term, or null when it could not be read (no student role, or STAG refused).'),
        ]);
    }
}
