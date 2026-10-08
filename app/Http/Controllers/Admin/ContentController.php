<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Grade;
use App\Models\Subject;
use App\Models\Topic;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    /**
     * Shared resource blade view with reference data (grades, subjects, …).
     */
    public function show(Request $request, ?string $resource = null)
    {
        $resource ??= basename($request->path());
        $config = config("platform.$resource");

        abort_if($config === null, 404, "Unknown resource [$resource].");

        if ($resource === 'questions') {
            return view('admin.questions');
        }
        if ($resource === 'announcements') {
            return view('admin.announcements');
        }

        return view('admin._resource', array_merge([
            'resource' => $resource,
            'title' => $this->title($resource),
            'pageDescription' => [
                'grades' => 'Set up the grade levels available to students.',
                'subjects' => 'Organize courses by grade. Add and number their units from Chapters.',
                'chapters' => 'Add chapter notes, publish them as one Telegram article, and upload an activation-gated chapter PDF.',
                'topics' => 'Group related questions and notes within a chapter.',
                'notes' => 'Write and edit lesson content here. When it is ready, open Chapters and publish all notes for that chapter as one Telegram article. Chapter PDFs are managed there too.',
                'questions' => 'Build practice questions, answer choices, and explanations.',
                'exams' => 'Create chapter tests and mock exams for the bot.',
                'announcements' => 'Write updates for all students or a selected grade.',
            ][$resource] ?? 'Manage records used by the Telegram bot.',
            'columns' => $this->columns($resource),
            'fields' => $this->fields($resource),
            'filters' => $this->filters($resource, $config),
            'searchable' => $config['search'] !== [],
            'searchPlaceholder' => 'Search '.strtolower($this->title($resource)).'…',
            'emptyText' => 'No '.$this->title($resource).' yet — create the first entry.',
        ], $this->referenceOptions()));
    }

    /**
     * Normalize config filters (plain field names) to the shape the Blade
     * template expects: ['name' => …, 'label' => …, 'options' => [['value','label']]].
     */
    protected function filters(string $resource, array $config): array
    {
        $static = [
            'difficulty' => ['label' => 'Difficulty', 'options' => [
                ['value' => 'easy', 'label' => 'Easy'],
                ['value' => 'medium', 'label' => 'Medium'],
                ['value' => 'hard', 'label' => 'Hard'],
            ]],
            'question_type' => ['label' => 'Type', 'options' => [
                ['value' => 'multiple_choice', 'label' => 'Multiple choice'],
                ['value' => 'true_false', 'label' => 'True / False'],
            ]],
            'type' => ['label' => 'Type', 'options' => [
                ['value' => 'chapter_test', 'label' => 'Chapter test'],
                ['value' => 'mock', 'label' => 'Mock exam'],
            ]],
            'audience' => ['label' => 'Audience', 'options' => [
                ['value' => 'all', 'label' => 'Everyone'],
                ['value' => 'grade', 'label' => 'A specific grade'],
            ]],
            'is_published' => ['label' => 'Status', 'options' => [
                ['value' => '1', 'label' => 'Published'],
                ['value' => '0', 'label' => 'Draft'],
            ]],
        ];

        $refs = [
            'grade_id' => ['label' => 'Grade', 'source' => 'grades'],
            'subject_id' => ['label' => 'Subject', 'source' => 'subjects'],
            'chapter_id' => ['label' => 'Chapter', 'source' => 'chapters'],
            'topic_id' => ['label' => 'Topic', 'source' => 'topics'],
        ];

        $out = [];

        foreach ($config['filters'] ?? [] as $name) {
            if (isset($static[$name])) {
                $out[] = array_merge(['name' => $name], $static[$name]);
            } elseif (isset($refs[$name])) {
                $out[] = [
                    'name' => $name,
                    'label' => $refs[$name]['label'],
                    'source' => $refs[$name]['source'],
                    'options' => $this->options($refs[$name]['source']),
                ];
            } else {
                $out[] = [
                    'name' => $name,
                    'label' => ucfirst(str_replace('_', ' ', $name)),
                    'options' => [],
                ];
            }
        }

        return $out;
    }

    /** Reference option lists (value/label) used by filter drop-downs. */
    protected function options(string $source): array
    {
        return match ($source) {
            'grades' => Grade::orderBy('level')->get(['id', 'name'])
                ->map(fn (Grade $r) => ['value' => $r->id, 'label' => $r->name])->all(),
            'subjects' => Subject::orderBy('order')->get(['id', 'grade_id', 'name'])
                ->map(fn (Subject $r) => ['value' => $r->id, 'label' => $r->name])->all(),
            'chapters' => Chapter::with('subject')->orderBy('order')->get(['id', 'subject_id', 'title', 'order'])
                ->map(fn (Chapter $r) => [
                    'value' => $r->id,
                    'label' => 'Chapter '.($r->order ?: '—').' · '.$r->title,
                ])->all(),
            'topics' => Topic::orderBy('order')->get(['id', 'chapter_id', 'title'])
                ->map(fn (Topic $r) => ['value' => $r->id, 'label' => $r->title])->all(),
            default => [],
        };
    }

    /** Kept for future use — forms load reference data from the API. */
    protected function referenceOptions(): array
    {
        return [];
    }

    protected function title(string $resource): string
    {
        return ucfirst(str_replace('_', ' ', $resource));
    }

    protected function columns(string $resource): array
    {
        $map = [];

        $map['grades'] = [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'level', 'label' => 'Level'],
            ['key' => 'description', 'label' => 'Description', 'render' => 'description'],
            ['key' => 'is_active', 'label' => 'Status', 'render' => 'status'],
        ];

        $map['subjects'] = [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'grade', 'label' => 'Grade', 'render' => 'grade'],
            ['key' => 'chapters', 'label' => 'Chapters / units', 'render' => 'subject_chapters'],
            ['key' => 'language', 'label' => 'Content language'],
            ['key' => 'order', 'label' => 'Subject order'],
            ['key' => 'is_active', 'label' => 'Status', 'render' => 'status'],
        ];

        $map['chapters'] = [
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'subject', 'label' => 'Subject', 'render' => 'subject'],
            ['key' => 'order', 'label' => 'Chapter / Unit #', 'render' => 'chapter_number'],
            ['key' => 'notes_count', 'label' => 'Lesson notes', 'render' => 'chapter_notes_count'],
            ['key' => 'is_active', 'label' => 'Status', 'render' => 'status'],
            ['key' => 'requires_activation', 'label' => 'Access', 'render' => 'chapter_access'],
            ['key' => 'telegraph_url', 'label' => 'Notes article', 'render' => 'chapter_article'],
            ['key' => 'pdf_file', 'label' => 'PDF', 'render' => 'chapter_pdf'],
        ];

        $map['topics'] = [
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'chapter', 'label' => 'Chapter / unit', 'render' => 'chapter'],
            ['key' => 'order', 'label' => 'Topic order'],
        ];

        $map['notes'] = [
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'chapter', 'label' => 'Chapter / unit', 'render' => 'chapter'],
            ['key' => 'subject', 'label' => 'Subject', 'render' => 'subject'],
            ['key' => 'content', 'label' => 'Preview', 'render' => 'content_preview'],
            ['key' => 'order', 'label' => 'Note order'],
        ];

        $map['questions'] = [
            ['key' => 'question_text', 'label' => 'Question', 'render' => 'question_text'],
            ['key' => 'subject', 'label' => 'Subject', 'render' => 'subject'],
            ['key' => 'difficulty', 'label' => 'Difficulty', 'render' => 'difficulty'],
            ['key' => 'question_type', 'label' => 'Type', 'render' => 'type'],
            ['key' => 'options', 'label' => 'Options', 'render' => 'option_count'],
        ];

        $map['exams'] = [
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'type', 'label' => 'Type', 'render' => 'type'],
            ['key' => 'subject', 'label' => 'Subject', 'render' => 'subject'],
            ['key' => 'chapter', 'label' => 'Chapter', 'render' => 'chapter'],
            ['key' => 'question_count', 'label' => 'Questions'],
            ['key' => 'time_limit_minutes', 'label' => 'Minutes'],
            ['key' => 'pass_mark', 'label' => 'Pass %'],
        ];

        $map['announcements'] = [
            ['key' => 'title', 'label' => 'Title'],
            ['key' => 'body', 'label' => 'Body', 'render' => 'body_preview'],
            ['key' => 'audience', 'label' => 'Audience', 'render' => 'audience'],
            ['key' => 'is_published', 'label' => 'Status', 'render' => 'published'],
        ];

        return $map[$resource] ?? [];
    }

    protected function fields(string $resource): array
    {
        return [
            'grades' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'placeholder' => 'Grade 6'],
                ['name' => 'level', 'label' => 'Level (school year)', 'type' => 'number', 'required' => true, 'placeholder' => '6'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'wide' => true],
                ['name' => 'is_active', 'label' => 'Visible to students', 'type' => 'checkbox'],
            ],
            'subjects' => [
                ['name' => 'grade_id', 'label' => 'Grade', 'type' => 'ref', 'source' => 'grades', 'required' => true],
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'placeholder' => 'Mathematics'],
                ['name' => 'language', 'label' => 'Question and explanation language', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'en', 'label' => 'English'],
                    ['value' => 'am', 'label' => 'Amharic'],
                ]],
                ['name' => 'order', 'label' => 'Subject display order', 'type' => 'number', 'hint' => 'This orders subjects in the bot. Add numbered units separately from the Chapters page.'],
                ['name' => 'is_active', 'label' => 'Visible to students', 'type' => 'checkbox'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'wide' => true],
            ],
            'chapters' => [
                ['name' => 'subject_id', 'label' => 'Subject', 'type' => 'ref', 'source' => 'subjects', 'required' => true],
                ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'placeholder' => 'Linear Equations'],
                ['name' => 'order', 'label' => 'Chapter / Unit number', 'type' => 'number', 'placeholder' => '1', 'hint' => 'Students see chapters in this order. Use 1, 2, 3, and so on.'],
                ['name' => 'is_active', 'label' => 'Visible to students', 'type' => 'checkbox'],
                ['name' => 'requires_activation', 'label' => 'Require account activation to access this chapter', 'type' => 'checkbox', 'hint' => 'When checked, free accounts cannot open this chapter’s notes or quizzes.'],
                ['name' => 'telegraph_url', 'label' => 'Published article URL', 'type' => 'text', 'hint' => 'Filled automatically when you use Publish notes.'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'wide' => true],
            ],
            'topics' => [
                ['name' => 'chapter_id', 'label' => 'Chapter', 'type' => 'ref', 'source' => 'chapters', 'required' => true],
                ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'placeholder' => 'Solving equations'],
                ['name' => 'order', 'label' => 'Topic order in chapter', 'type' => 'number'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'wide' => true],
            ],
            'notes' => [
                ['name' => 'grade_id', 'label' => 'Grade', 'type' => 'ref', 'source' => 'grades', 'required' => true],
                ['name' => 'subject_id', 'label' => 'Subject', 'type' => 'ref', 'source' => 'subjects', 'parent' => 'grade_id', 'parentField' => 'grade_id', 'required' => true],
                ['name' => 'chapter_id', 'label' => 'Chapter', 'type' => 'ref', 'source' => 'chapters', 'parent' => 'subject_id', 'parentField' => 'subject_id', 'required' => true],
                ['name' => 'topic_id', 'label' => 'Topic', 'type' => 'ref', 'source' => 'topics', 'parent' => 'chapter_id', 'parentField' => 'chapter_id', 'optional' => true],
                ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'wide' => true],
                ['name' => 'content', 'label' => 'Lesson content', 'type' => 'textarea', 'required' => true, 'wide' => true, 'rows' => 7, 'hint' => 'You can use simple HTML for headings, lists, and emphasis.'],
                ['name' => 'order', 'label' => 'Note order in chapter', 'type' => 'number'],
            ],
            'questions' => [
                ['name' => 'grade_id', 'label' => 'Grade', 'type' => 'ref', 'source' => 'grades', 'required' => true],
                ['name' => 'subject_id', 'label' => 'Subject', 'type' => 'ref', 'source' => 'subjects', 'parent' => 'grade_id', 'parentField' => 'grade_id', 'required' => true],
                ['name' => 'chapter_id', 'label' => 'Chapter', 'type' => 'ref', 'source' => 'chapters', 'parent' => 'subject_id', 'parentField' => 'subject_id', 'required' => true],
                ['name' => 'topic_id', 'label' => 'Topic', 'type' => 'ref', 'source' => 'topics', 'parent' => 'chapter_id', 'parentField' => 'chapter_id', 'optional' => true],
                ['name' => 'question_type', 'label' => 'Question type', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'multiple_choice', 'label' => 'Multiple choice'],
                    ['value' => 'true_false', 'label' => 'True / False'],
                ]],
                ['name' => 'difficulty', 'label' => 'Difficulty', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'easy', 'label' => 'Easy'],
                    ['value' => 'medium', 'label' => 'Medium'],
                    ['value' => 'hard', 'label' => 'Hard'],
                ]],
                ['name' => 'question_text', 'label' => 'Question text', 'type' => 'textarea', 'required' => true, 'wide' => true, 'rows' => 4],
                ['name' => 'options', 'label' => 'Answer options', 'type' => 'options', 'wide' => true, 'hint' => 'Add at least two choices and mark exactly one as correct.'],
                ['name' => 'explanation', 'label' => 'Explanation (shown after answering)', 'type' => 'textarea', 'wide' => true, 'rows' => 4],
                ['name' => 'source', 'label' => 'Source', 'type' => 'text', 'placeholder' => 'e.g. MoE 2019'],
                ['name' => 'year', 'label' => 'Year', 'type' => 'number', 'placeholder' => '2019'],
                ['name' => 'is_active', 'label' => 'Visible to students', 'type' => 'checkbox'],
            ],
            'exams' => [
                ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'wide' => true, 'placeholder' => 'Grade 8 Math — Mock #1'],
                ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'chapter_test', 'label' => 'Chapter test'],
                    ['value' => 'mock', 'label' => 'Mock exam'],
                ]],
                ['name' => 'grade_id', 'label' => 'Grade', 'type' => 'ref', 'source' => 'grades', 'required' => true],
                ['name' => 'subject_id', 'label' => 'Subject', 'type' => 'ref', 'source' => 'subjects', 'parent' => 'grade_id', 'parentField' => 'grade_id', 'required' => true],
                ['name' => 'chapter_id', 'label' => 'Chapter (empty for whole subject / mock)', 'type' => 'ref', 'source' => 'chapters', 'parent' => 'subject_id', 'parentField' => 'subject_id', 'optional' => true],
                ['name' => 'question_count', 'label' => 'Number of questions', 'type' => 'number', 'required' => true],
                ['name' => 'time_limit_minutes', 'label' => 'Time limit (minutes)', 'type' => 'number', 'required' => true],
                ['name' => 'pass_mark', 'label' => 'Pass mark (%)', 'type' => 'number', 'required' => true],
                ['name' => 'is_active', 'label' => 'Active (visible in bot)', 'type' => 'checkbox'],
            ],
            'announcements' => [
                ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'wide' => true],
                ['name' => 'body', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'wide' => true],
                ['name' => 'audience', 'label' => 'Send to', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'all', 'label' => 'Everyone'],
                    ['value' => 'grade', 'label' => 'A specific grade'],
                ]],
                ['name' => 'grade_id', 'label' => 'Grade (when audience = grade)', 'type' => 'ref', 'source' => 'grades', 'optional' => true],
                ['name' => 'is_published', 'label' => 'Publish now (students see it in the bot)', 'type' => 'checkbox'],
            ],
        ][$resource] ?? [];
    }
}
