<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\Question;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

class QuestionImportController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|max:10240',
            'grade_id' => 'required|exists:grades,id',
            'subject_id' => 'required|exists:subjects,id',
            'source' => 'nullable|string|max:200',
            'year' => 'nullable|integer|min:1900|max:2100',
        ]);

        $subject = Subject::findOrFail($data['subject_id']);
        abort_if((int) $subject->grade_id !== (int) $data['grade_id'], 422, 'Choose a subject that belongs to the selected grade.');

        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        abort_unless(in_array($extension, ['csv', 'txt', 'xlsx'], true), 422, 'Upload a CSV file or an Excel .xlsx file.');

        $matrix = $extension === 'xlsx'
            ? $this->readXlsx($request->file('file')->getRealPath())
            : $this->readCsv($request->file('file')->getRealPath());
        abort_if(count($matrix) < 2, 422, 'The file needs a header row and at least one question.');
        abort_if(count($matrix) > 1001, 422, 'Import up to 1,000 question rows at a time.');

        $headers = array_map(fn ($value) => $this->normalizeHeader((string) $value), array_shift($matrix));
        $columns = $this->columnMap($headers);
        abort_unless(isset($columns['question'], $columns['answer']) && count(array_intersect(['A', 'B', 'C', 'D'], array_keys($columns))) >= 2,
            422, 'Include Question, at least two choices (A–D), and Answer columns.');

        $seen = [];
        $rows = [];
        foreach ($matrix as $index => $cells) {
            if (count(array_filter($cells, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $get = fn ($key) => isset($columns[$key]) ? trim((string) ($cells[$columns[$key]] ?? '')) : '';
            $questionText = $get('question');
            $choiceTexts = array_filter([
                'A' => $get('A'), 'B' => $get('B'), 'C' => $get('C'), 'D' => $get('D'),
            ], fn ($value) => $value !== '');
            $answer = strtoupper($get('answer'));
            $correctLabel = null;
            foreach ($choiceTexts as $label => $text) {
                if ($answer === $label || mb_strtolower($answer) === mb_strtolower($text)) {
                    $correctLabel = $label;
                    break;
                }
            }

            $errors = [];
            if ($questionText === '') $errors[] = 'Question text is empty.';
            if (count($choiceTexts) < 2) $errors[] = 'Add at least two answer choices.';
            if ($correctLabel === null) $errors[] = 'Answer must match A, B, C, D, or the exact choice text.';

            $chapterNumber = $get('chapter');
            $chapter = $chapterNumber !== '' && ctype_digit($chapterNumber)
                ? Chapter::where('subject_id', $subject->id)->where('order', (int) $chapterNumber)->first()
                : null;
            if ($chapterNumber === '' || ! $chapter) $errors[] = 'Chapter number is missing or does not exist for this subject.';

            $yearText = $get('year');
            $rowYear = $yearText !== '' ? $yearText : ($data['year'] ?? null);
            if ($rowYear !== null && (! ctype_digit((string) $rowYear) || (int) $rowYear < 1900 || (int) $rowYear > 2100)) {
                $errors[] = 'Year must be between 1900 and 2100 E.C.';
            }

            $fingerprint = hash('sha256', ($chapter?->id ?? 'none').':'.mb_strtolower(trim($questionText)));
            $duplicate = isset($seen[$fingerprint]);
            $seen[$fingerprint] = true;
            if (! $duplicate && $chapter && $questionText !== '') {
                $duplicate = Question::where('grade_id', $data['grade_id'])
                    ->where('subject_id', $subject->id)
                    ->where('chapter_id', $chapter->id)
                    ->where('question_text', $questionText)
                    ->exists();
            }

            $rows[] = [
                'row' => $index + 2,
                'question_text' => $questionText,
                'options' => collect($choiceTexts)->map(fn ($text, $label) => [
                    'label' => $label,
                    'text' => $text,
                    'is_correct' => $label === $correctLabel,
                ])->values()->all(),
                'correct_answer' => $correctLabel,
                'chapter_number' => $chapterNumber,
                'chapter_id' => $chapter?->id,
                'explanation' => $get('explanation'),
                'source' => $get('source') ?: ($data['source'] ?? null),
                'year' => $rowYear !== null ? (int) $rowYear : null,
                'duplicate' => $duplicate,
                'errors' => $errors,
                'ready' => $errors === [] && ! $duplicate,
            ];
        }

        abort_if($rows === [], 422, 'No question rows were found under the header.');

        $key = (string) Str::uuid();
        Cache::put('question-import:'.$key, [
            'grade_id' => (int) $data['grade_id'],
            'subject_id' => (int) $subject->id,
            'rows' => $rows,
        ], now()->addMinutes(30));

        return response()->json([
            'preview_key' => $key,
            'rows' => $rows,
            'total' => count($rows),
            'ready' => count(array_filter($rows, fn ($row) => $row['ready'])),
            'duplicates' => count(array_filter($rows, fn ($row) => $row['duplicate'])),
            'needs_fix' => count(array_filter($rows, fn ($row) => $row['errors'] !== [])),
        ]);
    }

    public function commit(Request $request): JsonResponse
    {
        $data = $request->validate(['preview_key' => 'required|uuid']);
        $preview = Cache::get('question-import:'.$data['preview_key']);
        abort_if(! $preview, 404, 'This import preview expired. Upload the file again.');

        $readyRows = array_values(array_filter($preview['rows'], fn ($row) => $row['ready']));
        abort_if($readyRows === [], 422, 'There are no valid new questions to import.');

        [$imported, $skipped] = DB::transaction(function () use ($preview, $readyRows) {
            $count = 0;
            $skipped = count($preview['rows']) - count($readyRows);
            foreach ($readyRows as $row) {
                $exists = Question::where('grade_id', $preview['grade_id'])
                    ->where('subject_id', $preview['subject_id'])
                    ->where('chapter_id', $row['chapter_id'])
                    ->where('question_text', $row['question_text'])
                    ->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }
                $question = Question::create([
                    'grade_id' => $preview['grade_id'],
                    'subject_id' => $preview['subject_id'],
                    'chapter_id' => $row['chapter_id'],
                    'question_text' => $row['question_text'],
                    'question_type' => 'multiple_choice',
                    'difficulty' => 'medium',
                    'explanation' => $row['explanation'] ?: null,
                    'source' => $row['source'],
                    'year' => $row['year'],
                    'is_active' => true,
                ]);
                $question->options()->createMany($row['options']);
                $count++;
            }

            return [$count, $skipped];
        });

        Cache::forget('question-import:'.$data['preview_key']);

        return response()->json([
            'imported' => $imported,
            'skipped' => $skipped,
        ]);
    }

    protected function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        abort_if($handle === false, 422, 'Could not read the CSV file.');
        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = ',';
        if (is_string($firstLine)) {
            $counts = array_map(fn ($separator) => substr_count($firstLine, $separator), [',', ';', "\t"]);
            $delimiter = [',', ';', "\t"][array_keys($counts, max($counts))[0]];
        }

        $rows = [];
        while (($row = fgetcsv($handle, null, $delimiter)) !== false) {
            $rows[] = array_map(fn ($value) => is_string($value) ? trim($value) : '', $row);
        }
        fclose($handle);

        if (isset($rows[0][0])) $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);

        return $rows;
    }

    protected function readXlsx(string $path): array
    {
        abort_unless(class_exists(ZipArchive::class), 422, 'This server cannot read .xlsx files. Save the spreadsheet as CSV and upload it again.');
        $zip = new ZipArchive();
        abort_if($zip->open($path) !== true, 422, 'The Excel file could not be opened.');

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        abort_if($sheetXml === false, 422, 'The Excel file has no readable first worksheet.');
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        $sheet = simplexml_load_string($sheetXml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        abort_if(! $sheet, 422, 'The Excel worksheet is not valid XML.');
        $namespaces = $sheet->getNamespaces(true);
        $namespace = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet->registerXPathNamespace('x', $namespace);

        $shared = [];
        if (is_string($sharedXml)) {
            $strings = simplexml_load_string($sharedXml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($strings) {
                $strings->registerXPathNamespace('x', $namespace);
                foreach ($strings->xpath('//x:si') ?: [] as $item) {
                    $parts = $item->xpath('.//x:t') ?: [];
                    $shared[] = implode('', array_map('strval', $parts));
                }
            }
        }

        $matrix = [];
        foreach ($sheet->xpath('//x:sheetData/x:row') ?: [] as $row) {
            $values = [];
            foreach ($row->xpath('./x:c') ?: [] as $cell) {
                $reference = (string) $cell['r'];
                preg_match('/^[A-Z]+/', $reference, $match);
                if (! isset($match[0])) continue;
                $column = 0;
                foreach (str_split($match[0]) as $letter) $column = $column * 26 + ord($letter) - 64;
                $column--;
                $type = (string) $cell['t'];
                $cell->registerXPathNamespace('x', $namespace);
                if ($type === 'inlineStr') {
                    $parts = $cell->xpath('./x:is//x:t') ?: [];
                    $value = implode('', array_map('strval', $parts));
                } else {
                    $node = $cell->xpath('./x:v');
                    $value = isset($node[0]) ? (string) $node[0] : '';
                    if ($type === 's') $value = $shared[(int) $value] ?? '';
                }
                $values[$column] = trim($value);
            }
            if ($values !== []) {
                ksort($values);
                $matrix[] = array_replace(array_fill(0, max(array_keys($values)) + 1, ''), $values);
            }
        }

        return $matrix;
    }

    protected function normalizeHeader(string $header): string
    {
        return preg_replace('/[^a-z0-9]+/i', '', mb_strtolower(trim($header)));
    }

    protected function columnMap(array $headers): array
    {
        $aliases = [
            'question' => ['q', 'question', 'questiontext'],
            'A' => ['a', 'optiona', 'choicea', 'answera'],
            'B' => ['b', 'optionb', 'choiceb', 'answerb'],
            'C' => ['c', 'optionc', 'choicec', 'answerc'],
            'D' => ['d', 'optiond', 'choiced', 'answerd'],
            'answer' => ['ans', 'answer', 'correct', 'correctanswer'],
            'chapter' => ['chap', 'chapter', 'chapternumber', 'unit', 'unitnumber'],
            'explanation' => ['why', 'explanation', 'solution'],
            'source' => ['source', 'origin'],
            'year' => ['year', 'yearec', 'ecyear'],
        ];

        $columns = [];
        foreach ($headers as $index => $header) {
            foreach ($aliases as $key => $names) {
                if (in_array($header, $names, true)) $columns[$key] = $index;
            }
        }

        return $columns;
    }
}
