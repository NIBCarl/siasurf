<?php

namespace App\Services;

use App\Enums\SkillLevel;
use App\Models\StudentSkillAssessment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SkillAssessmentService
{
    /**
     * Public assessment definition for the UI (strips server-only answer keys).
     *
     * @return array<string, mixed>
     */
    public function publicDefinition(): array
    {
        $config = config('siasurf.skill_assessment');

        $questions = array_map(
            fn (array $q, int $index) => [
                'index' => $index,
                'key' => $q['key'],
                'topic' => $q['topic'],
                'question' => $q['question'],
                'options' => $q['options'],
                'required' => (bool) ($q['required'] ?? false),
            ],
            $config['questions'],
            array_keys($config['questions'])
        );

        return [
            'skills' => $config['skills'],
            'questions' => $questions,
            'thresholds' => $config['thresholds'],
        ];
    }

    /**
     * Score raw assessment input deterministically on the server.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $config = config('siasurf.skill_assessment');
        $thresholds = $config['thresholds'];
        $skillBank = $config['skills'];
        $questions = $config['questions'];

        $canSwim = (bool) ($input['can_swim_deep_water'] ?? false);
        $isFirstTime = (bool) ($input['is_first_time'] ?? false);

        $selectedSkills = [
            'basic' => $this->normalizeIndices($input['skills']['basic'] ?? [], count($skillBank['basic'])),
            'intermediate' => $this->normalizeIndices($input['skills']['intermediate'] ?? [], count($skillBank['intermediate'])),
            'advanced' => $this->normalizeIndices($input['skills']['advanced'] ?? [], count($skillBank['advanced'])),
        ];

        $basicCount = count($selectedSkills['basic']);
        $interCount = count($selectedSkills['intermediate']);
        $advCount = count($selectedSkills['advanced']);

        // Checklist tier: intermediate requires all basic + >=4 intermediate;
        // advanced additionally requires >=4 advanced.
        $checklistRank = 0;
        if ($basicCount === count($skillBank['basic']) && $interCount >= $thresholds['intermediate_skills_required']) {
            $checklistRank = 1;
            if ($advCount >= $thresholds['advanced_skills_required']) {
                $checklistRank = 2;
            }
        }

        $rawAnswers = $input['answers'] ?? [];
        $normalizedAnswers = [];
        $correctCount = 0;
        $waterSafetyPassed = false;

        foreach ($questions as $index => $question) {
            $answer = array_key_exists($index, $rawAnswers) && $rawAnswers[$index] !== null && $rawAnswers[$index] !== ''
                ? (int) $rawAnswers[$index]
                : null;

            $optionCount = count($question['options']);
            if ($answer !== null && ($answer < 0 || $answer >= $optionCount)) {
                $answer = null;
            }

            $normalizedAnswers[$index] = $answer;

            if ($answer !== null && $answer === (int) $question['correct_index']) {
                $correctCount++;
                if (($question['key'] ?? null) === 'water_safety') {
                    $waterSafetyPassed = true;
                }
            }
        }

        // Knowledge tier: intermediate requires >=3 correct including water safety;
        // advanced requires all 4 correct.
        $knowledgeRank = 0;
        if ($waterSafetyPassed && $correctCount >= $thresholds['intermediate_knowledge_required']) {
            $knowledgeRank = 1;
            if ($correctCount >= $thresholds['advanced_knowledge_required']) {
                $knowledgeRank = 2;
            }
        }

        $finalRank = min($checklistRank, $knowledgeRank);
        $flags = [];

        if ($isFirstTime) {
            $finalRank = 0;
            $flags[] = [
                'code' => 'first_time_or_unsure',
                'severity' => 'info',
                'message' => 'You said this is your first time or you’re not sure, so you start at beginner.',
            ];
        }

        if (! $canSwim) {
            $finalRank = 0;
            $flags[] = [
                'code' => 'deep_water_swimming_unconfirmed',
                'severity' => 'warning',
                'message' => 'Deep-water swimming was not confirmed. Your level is capped at beginner and the instructor sees a warning.',
            ];
        }

        if (! $waterSafetyPassed) {
            $finalRank = 0;
            $flags[] = [
                'code' => 'water_safety_incorrect',
                'severity' => 'warning',
                'message' => 'The water safety question was answered incorrectly. Your level is capped at beginner and the instructor sees a warning.',
            ];
        }

        return [
            'can_swim_deep_water' => $canSwim,
            'is_first_time' => $isFirstTime,
            'selected_skills' => $selectedSkills,
            'answers' => $normalizedAnswers,
            'checklist_level' => $this->rankToLevel($checklistRank)->value,
            'knowledge_level' => $this->rankToLevel($knowledgeRank)->value,
            'knowledge_score' => $correctCount,
            'knowledge_total' => count($questions),
            'water_safety_passed' => $waterSafetyPassed,
            'assessed_level' => $this->rankToLevel($finalRank)->value,
            'safety_flags' => $flags,
        ];
    }

    /**
     * Evaluate and persist a student's one-time pre-assessment, updating their
     * profile skill level atomically.
     *
     * @param  array<string, mixed>  $input
     */
    public function recordForStudent(User $student, array $input, ?string $birthday = null): StudentSkillAssessment
    {
        return DB::transaction(function () use ($student, $input, $birthday) {
            $profile = $student->studentProfile()->lockForUpdate()->first();

            if ($profile && $profile->skill_assessed_at !== null) {
                throw ValidationException::withMessages([
                    'assessment' => 'You have already completed your initial skill pre-assessment. Verified instructors can recommend a level upgrade after a lesson.',
                ]);
            }

            $evaluated = $this->evaluate($input);

            $assessment = StudentSkillAssessment::create([
                ...$evaluated,
                'user_id' => $student->id,
                'confirmed_at' => now(),
            ]);

            if (! $profile) {
                $profileData = [
                    'skill_level' => $evaluated['assessed_level'],
                    'is_first_time' => $evaluated['is_first_time'],
                    'skill_assessed_at' => $assessment->confirmed_at,
                ];

                if ($birthday !== null && $birthday !== '') {
                    $profileData['birthday'] = $birthday;
                }

                $profile = $student->studentProfile()->create($profileData);
            } else {
                $profileData = [
                    'skill_level' => $evaluated['assessed_level'],
                    'is_first_time' => $evaluated['is_first_time'],
                    'skill_assessed_at' => $assessment->confirmed_at,
                ];

                if ($birthday !== null && $birthday !== '') {
                    $profileData['birthday'] = $birthday;
                }

                $profile->forceFill($profileData)->save();
            }

            return $assessment;
        });
    }

    /**
     * Format a stored assessment for student result screens, instructors, and SISA admins.
     *
     * @return array<string, mixed>
     */
    public function formatForDisplay(StudentSkillAssessment $assessment): array
    {
        $config = config('siasurf.skill_assessment');
        $skillBank = $config['skills'];
        $questions = $config['questions'];

        $selected = $assessment->selected_skills ?? [];
        $tickedLabels = [];

        foreach (['basic', 'intermediate', 'advanced'] as $tier) {
            foreach ($selected[$tier] ?? [] as $idx) {
                if (isset($skillBank[$tier][$idx])) {
                    $tickedLabels[] = [
                        'tier' => $tier,
                        'label' => $skillBank[$tier][$idx],
                    ];
                }
            }
        }

        $answers = $assessment->answers ?? [];
        $questionSummary = [];

        foreach ($questions as $index => $q) {
            $chosen = $answers[$index] ?? null;
            $questionSummary[] = [
                'topic' => $q['topic'],
                'required' => (bool) ($q['required'] ?? false),
                'answered' => $chosen !== null,
                'selected_label' => $chosen !== null ? ($q['options'][$chosen] ?? null) : null,
                'correct' => $chosen !== null && (int) $chosen === (int) $q['correct_index'],
            ];
        }

        $level = SkillLevel::tryFrom($assessment->assessed_level) ?? SkillLevel::Beginner;

        return [
            'id' => $assessment->id,
            'assessed_level' => $level->value,
            'assessed_level_label' => $level->label(),
            'checklist_level' => $assessment->checklist_level,
            'knowledge_level' => $assessment->knowledge_level,
            'knowledge_score' => $assessment->knowledge_score,
            'knowledge_total' => $assessment->knowledge_total,
            'can_swim_deep_water' => $assessment->can_swim_deep_water,
            'is_first_time' => $assessment->is_first_time,
            'water_safety_passed' => $assessment->water_safety_passed,
            'safety_flags' => $assessment->safety_flags ?? [],
            'has_safety_warnings' => $assessment->hasSafetyWarnings(),
            'ticked_skills_count' => count($tickedLabels),
            'ticked_skills' => $tickedLabels,
            'question_summary' => $questionSummary,
            'bookable_instructors' => $level->instructorLevelRangeLabel(),
            'allowed_zones' => $level->surfSpotRuleLabel(),
            'assessed_at' => $assessment->confirmed_at?->toIso8601String(),
            'assessed_at_label' => $assessment->confirmed_at?->copy()->timezone(config('app.timezone', 'Asia/Manila'))->format('M j, Y g:i A'),
        ];
    }

    /**
     * @param  array<int, mixed>  $indices
     * @return array<int, int>
     */
    private function normalizeIndices(array $indices, int $maxCount): array
    {
        $clean = [];

        foreach ($indices as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $intVal = (int) $value;
            if ($intVal >= 0 && $intVal < $maxCount) {
                $clean[$intVal] = $intVal;
            }
        }

        ksort($clean);

        return array_values($clean);
    }

    private function rankToLevel(int $rank): SkillLevel
    {
        return match ($rank) {
            2 => SkillLevel::Advanced,
            1 => SkillLevel::Intermediate,
            default => SkillLevel::Beginner,
        };
    }
}
