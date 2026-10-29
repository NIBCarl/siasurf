<?php

namespace App\Enums;

enum AppealGround: string
{
    case NotMySession = 'not_my_session';
    case FactuallyIncorrect = 'factually_incorrect';
    case OutsideMyControl = 'outside_my_control';
    case Disproportionate = 'disproportionate_severity';
    case NewEvidence = 'new_evidence';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NotMySession => 'I was not the instructor for this session',
            self::FactuallyIncorrect => 'The report is factually incorrect',
            self::OutsideMyControl => 'The incident was outside my control',
            self::Disproportionate => 'The severity is disproportionate',
            self::NewEvidence => 'I have new evidence',
            self::Other => 'Other',
        };
    }
}
