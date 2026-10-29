<?php

namespace App\Enums;

/**
 * Structured post-session feedback categories.
 *
 * Positive categories are public review tags and never carry a strike.
 * Concern categories are private reports. The suggested severity is what the
 * student's chosen category proposes; SISA still confirms the final severity
 * before a strike is applied.
 */
enum FeedbackCategory: string
{
    // Positive / public review tags
    case PositiveCoaching = 'positive_coaching';
    case PositiveSafety = 'positive_safety';
    case PositiveLocalKnowledge = 'positive_local_knowledge';
    case PositiveEquipment = 'positive_equipment';
    case PositiveProfessionalism = 'positive_professionalism';
    case PositiveOther = 'positive_other';

    // Minor concerns - 1 strike
    case MinorLate = 'minor_late';
    case MinorCommunication = 'minor_communication';
    case MinorDistraction = 'minor_distraction';
    case MinorLessonPlan = 'minor_lesson_plan';
    case MinorEquipment = 'minor_equipment';
    case MinorLanguage = 'minor_language';
    case MinorOffPlatformPayment = 'minor_off_platform_payment';
    case MinorOther = 'minor_other';

    // Major concerns - 2 strikes
    case MajorNoSafetyBriefing = 'major_no_safety_briefing';
    case MajorConditionsIgnored = 'major_conditions_ignored';
    case MajorRatioExceeded = 'major_ratio_exceeded';
    case MajorSkillMismatch = 'major_skill_mismatch';
    case MajorSupervisionLapse = 'major_supervision_lapse';
    case MajorPressure = 'major_pressure';
    case MajorEquipmentSafety = 'major_equipment_safety';
    case MajorBoundaries = 'major_boundaries';
    case MajorFirstAid = 'major_first_aid';
    case MajorUnverifiedHelper = 'major_unverified_helper';
    case MajorOther = 'major_other';

    // Critical concerns - 3 strikes
    case CriticalAbandonment = 'critical_abandonment';
    case CriticalDangerousSpot = 'critical_dangerous_spot';
    case CriticalHarassment = 'critical_harassment';
    case CriticalIntoxication = 'critical_intoxication';
    case CriticalViolence = 'critical_violence';
    case CriticalContinuedAfterStop = 'critical_continued_after_stop';
    case CriticalUnverifiedTeaching = 'critical_unverified_teaching';
    case CriticalNegligence = 'critical_negligence';
    case CriticalFraud = 'critical_fraud';
    case CriticalOther = 'critical_other';

    // Unclassified concern - SISA assigns severity during review
    case OtherConcern = 'other_concern';

    private const LABELS = [
        'positive_coaching' => 'Clear coaching and encouragement',
        'positive_safety' => 'Felt safe and well supervised',
        'positive_local_knowledge' => 'Good local, tide, and current knowledge',
        'positive_equipment' => 'Appropriate board and equipment',
        'positive_professionalism' => 'Punctual, prepared, and professional',
        'positive_other' => 'Other positive comment',
        'minor_late' => 'Late start or early end without notice',
        'minor_communication' => 'Poor communication or unclear instructions',
        'minor_distraction' => 'Phone or social-media distraction during the lesson',
        'minor_lesson_plan' => 'Did not follow the agreed lesson plan',
        'minor_equipment' => 'Ill-fitting or poorly checked equipment',
        'minor_language' => 'Unprofessional language or attitude',
        'minor_off_platform_payment' => 'Asked to pay or cancel outside the platform',
        'minor_other' => 'Other minor concern',
        'major_no_safety_briefing' => 'No safety briefing before entering the water',
        'major_conditions_ignored' => 'Ignored tide, current, or weather warnings',
        'major_ratio_exceeded' => 'Exceeded the certified student ratio',
        'major_skill_mismatch' => 'Taught above the booked skill level',
        'major_supervision_lapse' => 'Briefly left the student or group unsupervised',
        'major_pressure' => 'Pressured the student beyond their comfort level',
        'major_equipment_safety' => 'Unsafe equipment or leash failure',
        'major_boundaries' => 'Unwanted personal comments or photos without consent',
        'major_first_aid' => 'Failed to provide first aid or injury response',
        'major_unverified_helper' => 'Allowed an unverified assistant to teach',
        'major_other' => 'Other major concern',
        'critical_abandonment' => 'Left the student alone or unattended in the water',
        'critical_dangerous_spot' => 'Took a beginner to a dangerous or advanced break',
        'critical_harassment' => 'Harassment, threats, or discrimination',
        'critical_intoxication' => 'Under the influence of alcohol or drugs',
        'critical_violence' => 'Physical assault or violence',
        'critical_continued_after_stop' => 'Continued after the student said stop or reported injury',
        'critical_unverified_teaching' => 'Taught without a valid certification',
        'critical_negligence' => 'Serious negligence causing or worsening injury',
        'critical_fraud' => 'Theft, extortion, or fraud',
        'critical_other' => 'Other critical concern',
        'other_concern' => 'Other concern (SISA will classify it)',
    ];


    private const DESCRIPTIONS = [
        'positive_coaching' => 'Explained the skill clearly and gave useful feedback.',
        'positive_safety' => 'Stayed with the group, checked conditions, and kept the lesson safe.',
        'positive_local_knowledge' => 'Chose the right break and tide for the session.',
        'positive_equipment' => 'Provided a suitable board, leash, and safety equipment.',
        'positive_professionalism' => 'Arrived prepared and treated the student respectfully.',
        'positive_other' => 'Share any other positive experience.',
        'minor_late' => 'More than 15 minutes late or ended the lesson early without notice.',
        'minor_communication' => 'Instructions were unclear or the instructor did not check in.',
        'minor_distraction' => 'Used a phone or social media instead of watching the student.',
        'minor_lesson_plan' => 'Ignored the agreed focus or lesson plan.',
        'minor_equipment' => 'Wrong board size or a missed equipment check, with no injury.',
        'minor_language' => 'Rude, dismissive, or unprofessional language that was not threatening.',
        'minor_off_platform_payment' => 'Asked the student to cancel or pay outside SiaSurf.',
        'minor_other' => 'Describe another minor professionalism or operational concern.',
        'major_no_safety_briefing' => 'Entered the water without a safety briefing.',
        'major_conditions_ignored' => 'Took the student out despite a tide, current, or weather warning.',
        'major_ratio_exceeded' => 'Had more students than the instructor level allows.',
        'major_skill_mismatch' => 'Pushed the student into conditions above their booked level.',
        'major_supervision_lapse' => 'Briefly lost sight of or separated from the student.',
        'major_pressure' => 'Repeatedly pushed the student beyond what they were comfortable with.',
        'major_equipment_safety' => 'Used a broken leash, damaged board, or unsafe equipment.',
        'major_boundaries' => 'Made unwanted personal comments or took photos without consent.',
        'major_first_aid' => 'Did not provide appropriate first aid after an injury.',
        'major_unverified_helper' => 'Another unverified person taught part of the lesson.',
        'major_other' => 'Describe another serious safety or conduct concern.',
        'critical_abandonment' => 'Left the student unsupervised or alone in the water.',
        'critical_dangerous_spot' => 'Took a beginner to an advanced or dangerous break such as Cloud 9.',
        'critical_harassment' => 'Sexual comments, unwanted touching, threats, or discrimination.',
        'critical_intoxication' => 'The instructor appeared to be under the influence.',
        'critical_violence' => 'Physical aggression or assault.',
        'critical_continued_after_stop' => 'Ignored a request to stop or an injury report.',
        'critical_unverified_teaching' => 'Taught without a valid or current certification.',
        'critical_negligence' => 'A serious action or inaction caused or worsened an injury.',
        'critical_fraud' => 'Theft, extortion, or fraudulent payment demands.',
        'critical_other' => 'Describe another critical safety or conduct concern.',
        'other_concern' => 'Describe what happened and SISA will assign the appropriate severity.',
    ];


    public function group(): string
    {
        return match (true) {
            str_starts_with($this->value, 'positive_') => 'positive',
            str_starts_with($this->value, 'minor_') => 'minor',
            str_starts_with($this->value, 'major_') => 'major',
            str_starts_with($this->value, 'critical_') => 'critical',
            default => 'concern',
        };
    }

    public function label(): string
    {
        return self::LABELS[$this->value] ?? str_replace('_', ' ', ucfirst($this->value));
    }

    public function description(): string
    {
        return self::DESCRIPTIONS[$this->value] ?? '';
    }

    public function isPositive(): bool
    {
        return $this->group() === 'positive';
    }

    public function isConcern(): bool
    {
        return ! $this->isPositive();
    }

    public function suggestedSeverity(): ?IncidentSeverity
    {
        return match ($this->group()) {
            'minor' => IncidentSeverity::Minor,
            'major' => IncidentSeverity::Major,
            'critical' => IncidentSeverity::Critical,
            default => null,
        };
    }

    public function suggestedStrikeWeight(): ?int
    {
        return $this->suggestedSeverity()?->strikes();
    }

    public function defaultIncidentType(): IncidentType
    {
        return IncidentType::RuleViolation;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function positiveOptions(): array
    {
        return collect(self::cases())
            ->filter(fn (self $category) => $category->isPositive())
            ->map(fn (self $category) => $category->toOption())
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function concernOptions(): array
    {
        return collect(self::cases())
            ->filter(fn (self $category) => $category->isConcern())
            ->map(fn (self $category) => $category->toOption())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toOption(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'description' => $this->description(),
            'group' => $this->group(),
            'severity' => $this->suggestedSeverity()?->value,
            'severity_label' => $this->suggestedSeverity()?->label(),
            'strikes' => $this->suggestedStrikeWeight(),
        ];
    }
}
