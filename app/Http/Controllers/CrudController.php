<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\Question;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Config-driven REST CRUD, driven by config/platform.php.
 * Routes: /api/v1/{resource} and /api/v1/{resource}/{id}
 */
class CrudController extends Controller
{
    /** GET /api/v1/{resource} — paginated list with filters and search. */
    public function index(Request $request, string $resource): JsonResponse
    {
        $config = $this->config($resource);
        $query = $config['model']::query()->with($config['with'] ?? []);

        foreach ($config['filters'] ?? [] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('search') && ! empty($config['search'])) {
            $term = '%'.$request->input('search').'%';
            $query->where(function ($q) use ($config, $term) {
                foreach ($config['search'] as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }

        // Support a leading "-" for descending sort (e.g. '-id' = newest first).
        $sort = (string) ($config['sort'] ?? 'id');
        $descending = str_starts_with($sort, '-');

        $query->orderBy(ltrim($sort, '-'), $descending ? 'desc' : 'asc');

        $perPage = min((int) $request->input('per_page', 15), 100);

        return response()->json($query->paginate($perPage));
    }

    /** POST /api/v1/{resource} */
    public function store(Request $request, string $resource): JsonResponse
    {
        $config = $this->config($resource);
        $data = $this->validateData($request, $config);
        $this->validateUniqueTuples($request, $config, null);

        $this->beforeSave($resource, $data, $request);

        $model = $config['model']::create($data);
        $this->afterSave($resource, $model, $request);

        return response()->json($model->fresh($config['with'] ?? []), 201);
    }

    /** GET /api/v1/{resource}/{id} */
    public function show(string $resource, int $id): JsonResponse
    {
        $config = $this->config($resource);
        $model = $config['model']::with($config['with'] ?? [])->findOrFail($id);

        return response()->json($model);
    }

    /** PUT/PATCH /api/v1/{resource}/{id} */
    public function update(Request $request, string $resource, int $id): JsonResponse
    {
        $config = $this->config($resource);
        $model = $config['model']::findOrFail($id);
        $data = $this->validateData($request, $config, $id);
        $this->validateUniqueTuples($request, $config, $id);

        $this->beforeSave($resource, $data, $request);

        $model->update($data);
        $this->afterSave($resource, $model, $request);

        return response()->json($model->fresh($config['with'] ?? []));
    }

    /** DELETE /api/v1/{resource}/{id} */
    public function destroy(string $resource, int $id): JsonResponse
    {
        $config = $this->config($resource);
        $config['model']::findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Validate against the resource rules, expanding unique constraints.
     *
     * Rules stay plain strings in config (config:cache-safe). Conventions:
     *  - a 'unique' token inside a field's rules expands to
     *    unique:{table},{field}{,id} (the update id is ignored, so a row
     *    doesn't collide with itself);
     *  - a 'unique' => [[col, ...], ...] entry validates composite unique
     *    indexes, since Laravel's string syntax can't express them.
     */
    protected function validateData(Request $request, array $config, ?int $id = null): array
    {
        $table = (new $config['model'])->getTable();
        $ignore = $id ? ",{$id}" : '';
        $rules = [];

        foreach ($config['rules'] as $field => $rule) {
            $parts = explode('|', (string) $rule);

            if (($key = array_search('unique', $parts, true)) !== false && count($parts) > 1) {
                $parts[$key] = "unique:{$table},{$field}{$ignore}";
            }

            $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

            if ($parts !== []) {
                $rules[$field] = implode('|', $parts);
            }
        }

        return $request->validate($rules);
    }

    /** Validate composite unique indexes declared as 'unique' => [[col, ...]]. */
    protected function validateUniqueTuples(Request $request, array $config, ?int $id): void
    {
        foreach ($config['unique'] ?? [] as $tuple) {
            $query = $config['model']::where(function ($q) use ($tuple, $request) {
                foreach ($tuple as $column) {
                    $q->where($column, $request->input($column));
                }
            });

            if ($id !== null) {
                $query->whereKeyNot($id);
            }

            if ($query->exists()) {
                throw ValidationException::withMessages([
                    $tuple[0] => ['This combination ('.implode(' + ', $tuple).') already exists.'],
                ]);
            }
        }
    }

    protected function config(string $resource): array
    {
        $config = config("platform.$resource");

        abort_if($config === null, 404, "Unknown resource [$resource].");

        return $config;
    }

    /** Resource-specific massaging before create/update. */
    protected function beforeSave(string $resource, array &$data, Request $request): void
    {
        // 'order' columns are default(0) but not nullable: an explicit null in
        // the INSERT would violate the NOT NULL constraint, so coerce to 0.
        if (in_array($resource, ['subjects', 'chapters', 'topics', 'notes'], true)
            && array_key_exists('order', $data)
            && $data['order'] === null) {
            $data['order'] = 0;
        }

        if ($resource === 'questions') {
            $data['year'] = $request->input('year') ?: null;
        }

        if ($resource === 'announcements' && ($data['is_published'] ?? false) && empty($data['published_at'])) {
            $data['published_at'] = now();
        }
    }

    /** Resource-specific nested writes after the parent row exists. */
    protected function afterSave(string $resource, Model $model, Request $request): void
    {
        match ($resource) {
            'questions' => $this->saveOptions($model, $request->input('options', [])),
            'exams' => $this->savePinnedQuestions($model, $request->input('question_ids')),
            'announcements' => $this->syncPublishedAt($model, $request),
            default => null,
        };
    }

    protected function saveOptions(Question $question, array $options): void
    {
        $correctLabels = array_column(array_filter($options, fn ($o) => $o['is_correct']), 'label');
        abort_if(count($correctLabels) !== 1, 422, 'Exactly one option must be correct.');

        $question->options()->delete();

        foreach ($options as $option) {
            $question->options()->create([
                'label' => $option['label'],
                'text' => $option['text'],
                'is_correct' => (bool) $option['is_correct'],
            ]);
        }
    }

    protected function savePinnedQuestions(Exam $exam, ?array $questionIds): void
    {
        if ($questionIds === null) {
            return;
        }

        $exam->pinnedQuestions()->sync(
            array_values(array_unique(array_map('intval', $questionIds)))
        );
    }

    protected function syncPublishedAt(Model $announcement, Request $request): void
    {
        if ($request->boolean('is_published') && ! $announcement->published_at) {
            $announcement->forceFill(['published_at' => now()])->save();
        }
    }
}
