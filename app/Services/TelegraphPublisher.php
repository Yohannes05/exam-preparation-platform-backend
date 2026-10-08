<?php

namespace App\Services;

use App\Models\Chapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TelegraphPublisher
{
    public function publish(Chapter $chapter): array
    {
        $token = config('services.telegraph.access_token');
        if (! $token) throw new RuntimeException('Set TELEGRAPH_ACCESS_TOKEN in .env before publishing chapter notes.');

        $chapter->loadMissing('subject.grade', 'notes');
        if ($chapter->notes->isEmpty()) throw new RuntimeException('Add at least one note to this chapter before publishing.');

        $content = [];
        foreach ($chapter->notes as $note) {
            $content[] = ['tag' => 'h3', 'children' => [$note->title]];
            array_push($content, ...$this->htmlNodes($note->content));
            if ($note->image_file) {
                $imageUrl = preg_match('~^https?://~i', $note->image_file)
                    ? $note->image_file
                    : url(Storage::disk('public')->url($note->image_file));
                $content[] = ['tag' => 'img', 'attrs' => ['src' => $imageUrl]];
            }
        }
        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 64000) throw new RuntimeException('This chapter article is over Telegraph’s 64 KB limit. Shorten the note content before publishing.');

        $title = trim('Grade '.$chapter->subject->grade->level.' · '.$chapter->subject->name.' · Chapter '.($chapter->order ?: '').' '.$chapter->title);
        $payload = ['access_token' => $token, 'title' => $title, 'content' => $json, 'author_name' => config('app.name')];
        if ($chapter->telegraph_path) {
            $result = $this->request('editPage/'.$chapter->telegraph_path, $payload + ['return_content' => false]);
        } else {
            $result = $this->request('createPage', $payload + ['return_content' => false]);
        }

        $page = $result['result'];
        $chapter->forceFill(['telegraph_url' => $page['url'], 'telegraph_path' => $page['path']])->save();
        return $page;
    }

    private function request(string $method, array $payload): array
    {
        $response = Http::asForm()->timeout(20)->post('https://api.telegra.ph/'.$method, $payload);
        if (! $response->successful() || ! $response->json('ok')) {
            throw new RuntimeException((string) ($response->json('error') ?? 'Telegraph publishing failed.'));
        }
        return $response->json();
    }

    private function htmlNodes(string $html): array
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $doc->getElementsByTagName('div')->item(0);
        $nodes = [];
        foreach ($root?->childNodes ?? [] as $child) array_push($nodes, ...$this->convert($child));
        return $nodes ?: [['tag' => 'p', 'children' => ['']]];
    }

    private function convert(\DOMNode $node): array
    {
        if ($node instanceof \DOMText) {
            $text = trim($node->nodeValue ?? '');
            return $text === '' ? [] : [$text];
        }
        if (! $node instanceof \DOMElement) return [];
        $allowed = ['p','br','h3','h4','strong','b','em','i','u','s','blockquote','pre','code','ul','ol','li','a','img'];
        $tag = strtolower($node->tagName);
        $children = [];
        foreach ($node->childNodes as $child) array_push($children, ...$this->convert($child));
        if (! in_array($tag, $allowed, true)) return $children;
        $attrs = [];
        if ($tag === 'a' && $node->hasAttribute('href') && preg_match('~^https?://~i', $node->getAttribute('href'))) $attrs['href'] = $node->getAttribute('href');
        if ($tag === 'img' && $node->hasAttribute('src') && preg_match('~^https?://~i', $node->getAttribute('src'))) $attrs['src'] = $node->getAttribute('src');
        if ($tag === 'img' && ! isset($attrs['src'])) return [];
        return [['tag' => $tag] + ($attrs ? ['attrs' => $attrs] : []) + (in_array($tag, ['br','img'], true) ? [] : ['children' => $children])];
    }
}
