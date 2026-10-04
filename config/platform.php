<?php

use App\Models\Announcement;
use App\Models\Chapter;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Note;
use App\Models\Question;
use App\Models\Subject;
use App\Models\Topic;

/*
|--------------------------------------------------------------------------
| Admin API resource registry
|--------------------------------------------------------------------------
| Drives the generic REST CRUD controller (app/Http/Controllers/CrudController).
| Each entry maps a URL segment to a model, validation rules and query hints.
| Only strings/arrays so the config stays serializable for config:cache.
*/

return [
    'grades' => [
        'model' => Grade::class,
        'search' => ['name'],
        'sort' => 'level',
        'with' => [],
        'rules' => [
            'name' => 'required|string|max:100',
            'level' => 'required|unique|integer|min:1|max:12',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ],
    ],

    'subjects' => [
        'model' => Subject::class,
        'search' => ['name'],
        'sort' => 'order',
        'with' => ['grade'],
        'filters' => ['grade_id'],
        'rules' => [
            'grade_id' => 'required|exists:grades,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ],
        'unique' => [['grade_id', 'name']],
    ],

    'chapters' => [
        'model' => Chapter::class,
        'search' => ['title'],
        'sort' => 'order',
        'with' => ['subject.grade'],
        'filters' => ['subject_id'],
        'rules' => [
            'subject_id' => 'required|exists:subjects,id',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ],
    ],

    'topics' => [
        'model' => Topic::class,
        'search' => ['title'],
        'sort' => 'order',
        'with' => ['chapter'],
        'filters' => ['chapter_id'],
        'rules' => [
            'chapter_id' => 'required|exists:chapters,id',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'order' => 'nullable|integer|min:0',
        ],
    ],

    'notes' => [
        'model' => Note::class,
        'search' => ['title'],
        'sort' => 'order',
        'with' => ['grade', 'subject', 'chapter', 'topic'],
        'filters' => ['grade_id', 'subject_id', 'chapter_id', 'topic_id'],
        'rules' => [
            'grade_id' => 'required|exists:grades,id',
            'subject_id' => 'required|exists:subjects,id',
            'chapter_id' => 'required|exists:chapters,id',
            'topic_id' => 'nullable|exists:topics,id',
            'title' => 'required|string|max:200',
            'content' => 'required|string',
            'order' => 'nullable|integer|min:0',
        ],
    ],

    'questions' => [
        'model' => Question::class,
        'search' => ['question_text'],
        'sort' => '-id',
        'with' => ['grade', 'subject', 'chapter', 'topic', 'options'],
        'filters' => ['grade_id', 'subject_id', 'chapter_id', 'topic_id', 'difficulty', 'question_type'],
        'rules' => [
            'grade_id' => 'required|exists:grades,id',
            'subject_id' => 'required|exists:subjects,id',
            'chapter_id' => 'required|exists:chapters,id',
            'topic_id' => 'nullable|exists:topics,id',
            'question_text' => 'required|string',
            'question_type' => 'required|in:multiple_choice,true_false',
            'difficulty' => 'required|in:easy,medium,hard',
            'explanation' => 'nullable|string',
            'source' => 'nullable|string|max:200',
            'year' => 'nullable|integer|min:1900|max:2100',
            'is_active' => 'boolean',
            'options' => 'required|array|min:2',
            'options.*.label' => 'required|string|max:4',
            'options.*.text' => 'required|string',
            'options.*.is_correct' => 'required|boolean',
        ],
    ],

    'exams' => [
        'model' => Exam::class,
        'search' => ['title'],
        'sort' => '-id',
        'with' => ['grade', 'subject', 'chapter'],
        'filters' => ['grade_id', 'subject_id', 'type'],
        'rules' => [
            'title' => 'required|string|max:200',
            'type' => 'required|in:chapter_test,mock',
            'grade_id' => 'required|exists:grades,id',
            'subject_id' => 'required|exists:subjects,id',
            'chapter_id' => 'nullable|exists:chapters,id',
            'question_count' => 'required|integer|min:1|max:200',
            'time_limit_minutes' => 'required|integer|min:1|max:600',
            'pass_mark' => 'required|integer|min:0|max:100',
            'distribution' => 'nullable|array',
            'is_active' => 'boolean',
            'question_ids' => 'nullable|array',
            'question_ids.*' => 'integer|exists:questions,id',
        ],
    ],

    'announcements' => [
        'model' => Announcement::class,
        'search' => ['title'],
        'sort' => '-id',
        'with' => ['grade'],
        'filters' => ['audience', 'is_published'],
        'rules' => [
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'audience' => 'required|in:all,grade',
            'grade_id' => 'nullable|exists:grades,id',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
        ],
    ],
];
