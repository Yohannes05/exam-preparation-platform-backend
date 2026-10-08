<?php

namespace Database\Seeders;

use App\Models\Chapter;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Note;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Sample/test content only (spec §31: system first, real curriculum later).
 * Administrators replace this through the dashboard once the software is stable.
 */
class SampleContentSeeder extends Seeder
{
    public function run(): void
    {
        $grades = [
            ['name' => 'Grade 6', 'level' => 6, 'description' => 'Primary school final year'],
            ['name' => 'Grade 8', 'level' => 8, 'description' => 'Middle school final year — national exam'],
            ['name' => 'Grade 12', 'level' => 12, 'description' => 'Secondary school final year — national exam'],
        ];

        foreach ($grades as $g) {
            Grade::firstOrCreate(['level' => $g['level']], $g);
        }

        $subjectsByGrade = [
            6 => ['Mathematics', 'English', 'Amharic', 'Environmental Science', 'General Science', 'Civics'],
            8 => ['Mathematics', 'Physics', 'Biology', 'English', 'Amharic'],
            12 => ['Mathematics', 'Physics', 'Chemistry', 'Biology', 'Amharic'],
        ];

        foreach ($subjectsByGrade as $level => $subjects) {
            $grade = Grade::where('level', $level)->first();

            foreach ($subjects as $i => $name) {
                $language = $name === 'Amharic' || ($level === 6 && in_array($name, ['General Science', 'Civics'], true))
                    ? 'am'
                    : 'en';

                Subject::updateOrCreate(
                    ['grade_id' => $grade->id, 'name' => $name],
                    ['language' => $language, 'order' => $i + 1]
                );
            }
        }

        $this->seedMathChapters();
        $this->seedPhysicsChapters();
        $this->seedNotesAndQuestions();
        $this->seedExams();
    }

    protected function seedMathChapters(): void
    {
        foreach ([6, 8, 12] as $level) {
            $grade = Grade::where('level', $level)->first();
            $math = Subject::where('grade_id', $grade->id)->where('name', 'Mathematics')->first();

            $chapters = match ($level) {
                6 => ['Whole Numbers', 'Fractions', 'Decimals', 'Geometry Basics'],
                8 => ['Linear Equations', 'Exponents & Radicals', 'Geometry', 'Statistics'],
                12 => ['Sets, Relations & Functions', 'Trigonometry', 'Sequences & Series', 'Calculus Basics'],
            };

            foreach ($chapters as $i => $title) {
                $chapter = Chapter::firstOrCreate(
                    ['subject_id' => $math->id, 'title' => $title],
                    ['order' => $i + 1, 'description' => "Sample chapter: $title", 'requires_activation' => $i >= 3]
                );

                Topic::firstOrCreate(
                    ['chapter_id' => $chapter->id, 'title' => $title.' — Overview'],
                    ['order' => 1]
                );
                Topic::firstOrCreate(
                    ['chapter_id' => $chapter->id, 'title' => $title.' — Worked Examples'],
                    ['order' => 2]
                );
            }
        }
    }

    protected function seedPhysicsChapters(): void
    {
        foreach ([8, 12] as $level) {
            $grade = Grade::where('level', $level)->first();
            $physics = Subject::where('grade_id', $grade->id)->where('name', 'Physics')->first();

            $chapters = $level === 8
                ? ['Motion', 'Force & Pressure', 'Energy']
                : ['Kinematics', 'Newton\'s Laws', 'Work, Energy & Power', 'Waves'];

            foreach ($chapters as $i => $title) {
                Chapter::firstOrCreate(
                    ['subject_id' => $physics->id, 'title' => $title],
                    ['order' => $i + 1, 'description' => "Sample chapter: $title", 'requires_activation' => $i >= 3]
                );
            }
        }
    }

    protected function seedNotesAndQuestions(): void
    {
        // --- Grade 8 Mathematics: Linear Equations ---
        $g8 = Grade::where('level', 8)->first();
        $math = Subject::where('grade_id', $g8->id)->where('name', 'Mathematics')->first();
        $linear = Chapter::where('subject_id', $math->id)->where('title', 'Linear Equations')->first();

        Note::firstOrCreate(
            ['chapter_id' => $linear->id, 'title' => 'Linear Equations — Study Notes'],
            [
                'grade_id' => $g8->id,
                'subject_id' => $math->id,
                'topic_id' => $linear->topics()->first()?->id,
                'content' => implode("\n\n", [
                    '<b>Linear Equations</b>',
                    'A linear equation is an equation where the highest power of the variable is 1.',
                    'Example: 2x + 3 = 11',
                    'Key points:',
                    '• Do the same operation to both sides',
                    '• Isolate the variable step by step',
                    '• Check your answer by substituting it back',
                    'Worked example: 2x + 3 = 11 → 2x = 8 → x = 4',
                ]),
            ]
        );

        $this->mcq($g8, $math, $linear, [
            'question' => 'Solve for x:  2x + 3 = 11',
            'options' => ['x = 3' => false, 'x = 4' => true, 'x = 5' => false, 'x = 7' => false],
            'year' => 2024,
            'explanation' => 'Subtract 3 from both sides: 2x = 8. Divide by 2: x = 4.',
            'difficulty' => 'easy',
            'topic' => 'Linear Equations — Overview',
        ]);

        $this->mcq($g8, $math, $linear, [
            'question' => 'Which of the following is a linear equation?',
            'options' => ['x² + 1 = 0' => false, '3x + 2 = 10' => true, '1/x = 4' => false, '√x = 9' => false],
            'year' => 2024,
            'explanation' => 'A linear equation has the variable raised to the power of 1 only.',
            'difficulty' => 'medium',
            'topic' => 'Linear Equations — Overview',
        ]);

        $this->mcq($g8, $math, $linear, [
            'question' => 'If 5x − 7 = 3x + 5, what is x?',
            'options' => ['x = 2' => false, 'x = 4' => false, 'x = 6' => true, 'x = 8' => false],
            'year' => 2024,
            'explanation' => '5x − 3x = 5 + 7 → 2x = 12 → x = 6. Check: 5(6) − 7 = 23 and 3(6) + 5 = 23. ✓',
            'difficulty' => 'hard',
            'topic' => 'Linear Equations — Worked Examples',
        ]);

        // --- Grade 8 Physics: Motion ---
        $physics = Subject::where('grade_id', $g8->id)->where('name', 'Physics')->first();
        $motion = Chapter::where('subject_id', $physics->id)->where('title', 'Motion')->first();

        Note::firstOrCreate(
            ['chapter_id' => $motion->id, 'title' => 'Motion — Study Notes'],
            [
                'grade_id' => $g8->id,
                'subject_id' => $physics->id,
                'content' => implode("\n\n", [
                    '<b>Motion</b>',
                    'Motion is a change in position of an object over time.',
                    'Speed = distance ÷ time',
                    'Velocity includes direction; speed does not.',
                    'Acceleration = change in velocity ÷ time',
                    'Example: a car travels 100 m in 20 s → speed = 5 m/s',
                ]),
            ]
        );

        $this->mcq($g8, $physics, $motion, [
            'question' => 'A car travels 100 metres in 20 seconds. What is its average speed?',
            'options' => ['2 m/s' => false, '5 m/s' => true, '20 m/s' => false, '50 m/s' => false],
            'year' => 2024,
            'explanation' => 'Speed = distance ÷ time = 100 ÷ 20 = 5 m/s.',
            'difficulty' => 'easy',
        ]);

        $this->mcq($g8, $physics, $motion, [
            'question' => 'Which quantity includes direction?',
            'options' => ['Speed' => false, 'Distance' => false, 'Velocity' => true, 'Time' => false],
            'year' => 2024,
            'explanation' => 'Velocity is speed in a specified direction, so it is a vector quantity.',
            'difficulty' => 'medium',
        ]);

        $this->trueFalse($g8, $physics, $motion, [
            'question' => 'An object at rest has zero velocity.',
            'answer' => true,
            'year' => 2024,
            'explanation' => 'At rest means no change in position, so velocity is zero.',
            'difficulty' => 'easy',
        ]);

        // --- Grade 12 Mathematics: Trigonometry ---
        $g12 = Grade::where('level', 12)->first();
        $math12 = Subject::where('grade_id', $g12->id)->where('name', 'Mathematics')->first();
        $trig = Chapter::where('subject_id', $math12->id)->where('title', 'Trigonometry')->first();

        Note::firstOrCreate(
            ['chapter_id' => $trig->id, 'title' => 'Trigonometry — Study Notes'],
            [
                'grade_id' => $g12->id,
                'subject_id' => $math12->id,
                'content' => implode("\n\n", [
                    '<b>Trigonometry</b>',
                    'Core ratios: sin θ = opposite/hypotenuse, cos θ = adjacent/hypotenuse, tan θ = opposite/adjacent.',
                    'Pythagorean identity: sin²θ + cos²θ = 1',
                    'sin(A ± B) and cos(A ± B) addition formulas.',
                    'The sine rule: a/sin A = b/sin B = c/sin C',
                ]),
            ]
        );

        $this->mcq($g12, $math12, $trig, [
            'question' => 'What is sin 30°?',
            'options' => ['0' => false, '1/2' => true, '√2/2' => false, '√3/2' => false],
            'year' => 2025,
            'explanation' => 'sin 30° = 1/2 (standard exact value).',
            'difficulty' => 'easy',
        ]);

        $this->mcq($g12, $math12, $trig, [
            'question' => 'Which identity is correct?',
            'options' => [
                'sin²θ + cos²θ = 1' => true,
                'sin²θ − cos²θ = 1' => false,
                'tan θ = sin θ · cos θ' => false,
                '1 + tan²θ = sin²θ' => false,
            ],
            'year' => 2025,
            'explanation' => 'The Pythagorean identity: sin²θ + cos²θ = 1.',
            'difficulty' => 'medium',
        ]);

        // --- Grade 6 Mathematics: Fractions ---
        $g6 = Grade::where('level', 6)->first();
        $math6 = Subject::where('grade_id', $g6->id)->where('name', 'Mathematics')->first();
        $fractions = Chapter::where('subject_id', $math6->id)->where('title', 'Fractions')->first();

        Note::firstOrCreate(
            ['chapter_id' => $fractions->id, 'title' => 'Fractions — Study Notes'],
            [
                'grade_id' => $g6->id,
                'subject_id' => $math6->id,
                'content' => implode("\n\n", [
                    '<b>Fractions</b>',
                    'A fraction shows part of a whole: numerator (top) ÷ denominator (bottom).',
                    'To add fractions, make the denominators the same first.',
                    'Example: 1/4 + 1/4 = 2/4 = 1/2',
                ]),
            ]
        );

        $this->mcq($g6, $math6, $fractions, [
            'question' => 'What is 1/4 + 1/4?',
            'options' => ['1/8' => false, '1/2' => true, '2/8' => false, '1/4' => false],
            'year' => 2023,
            'explanation' => '1/4 + 1/4 = 2/4, which simplifies to 1/2.',
            'difficulty' => 'easy',
        ]);

        $this->mcq($g6, $math6, $fractions, [
            'question' => 'Which fraction is equivalent to 1/2?',
            'options' => ['2/3' => false, '3/6' => true, '4/6' => false, '2/6' => false],
            'year' => 2023,
            'explanation' => 'Multiply numerator and denominator of 1/2 by 3: 3/6.',
            'difficulty' => 'medium',
        ]);
    }

    protected function mcq(Grade $grade, Subject $subject, Chapter $chapter, array $data): void
    {
        $topic = ! empty($data['topic'])
            ? Topic::where('chapter_id', $chapter->id)->where('title', $data['topic'])->first()
            : null;

        $question = Question::firstOrCreate(
            [
                'chapter_id' => $chapter->id,
                'question_text' => $data['question'],
            ],
            [
                'grade_id' => $grade->id,
                'subject_id' => $subject->id,
                'topic_id' => $topic?->id,
                'year' => $data['year'] ?? null,
                'question_type' => 'multiple_choice',
                'difficulty' => $data['difficulty'] ?? 'medium',
                'explanation' => $data['explanation'] ?? null,
                'source' => 'Sample content',
            ]
        );

        if ($question->options()->exists()) {
            return;
        }

        $label = 'A';
        foreach ($data['options'] as $text => $isCorrect) {
            $question->options()->create([
                'label' => $label,
                'text' => $text,
                'is_correct' => $isCorrect,
            ]);
            $label++;
        }
    }

    protected function trueFalse(Grade $grade, Subject $subject, Chapter $chapter, array $data): void
    {
        $question = Question::firstOrCreate(
            [
                'chapter_id' => $chapter->id,
                'question_text' => $data['question'],
            ],
            [
                'grade_id' => $grade->id,
                'subject_id' => $subject->id,
                'year' => $data['year'] ?? null,
                'question_type' => 'true_false',
                'difficulty' => $data['difficulty'] ?? 'medium',
                'explanation' => $data['explanation'] ?? null,
                'source' => 'Sample content',
            ]
        );

        if ($question->options()->exists()) {
            return;
        }

        $question->options()->create(['label' => 'A', 'text' => 'True', 'is_correct' => $data['answer']]);
        $question->options()->create(['label' => 'B', 'text' => 'False', 'is_correct' => ! $data['answer']]);
    }

    protected function seedExams(): void
    {
        $g8 = Grade::where('level', 8)->first();
        $math = Subject::where('grade_id', $g8->id)->where('name', 'Mathematics')->first();
        $linear = Chapter::where('subject_id', $math->id)->where('title', 'Linear Equations')->first();

        Exam::firstOrCreate(
            ['title' => 'Linear Equations — Chapter Test'],
            [
                'type' => 'chapter_test',
                'grade_id' => $g8->id,
                'subject_id' => $math->id,
                'chapter_id' => $linear->id,
                'question_count' => 3,
                'time_limit_minutes' => 10,
                'pass_mark' => 60,
                'distribution' => ['difficulties' => ['easy' => 1, 'medium' => 1, 'hard' => 1]],
                'is_active' => true,
            ]
        );

        $physics = Subject::where('grade_id', $g8->id)->where('name', 'Physics')->first();

        Exam::firstOrCreate(
            ['title' => 'Grade 8 Physics — Mock Exam #1'],
            [
                'type' => 'mock',
                'grade_id' => $g8->id,
                'subject_id' => $physics->id,
                'chapter_id' => null,
                'question_count' => 3,
                'time_limit_minutes' => 15,
                'pass_mark' => 50,
                'distribution' => ['difficulties' => ['easy' => 1, 'medium' => 1, 'hard' => 1]],
                'is_active' => true,
            ]
        );
    }
}
