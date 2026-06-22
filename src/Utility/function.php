<?php

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Cleans and sanitizes a string input.
 * @param string $s The string to be cleaned.
 * @return string|null The cleaned and sanitized string, or null if input is null.
 */
function clean(?string $s): ?string
{
    if ($s === null)
        return null;
    return htmlspecialchars(stripcslashes(trim((string) $s)), ENT_QUOTES, 'UTF-8');
}

/**
 * Uncleans and unsanitizes a string input.
 * @param mixed $s The string to be uncleaned.
 * @return string|null The uncleaned and unsanitized string, or null if input is null.
 */
function unClean(mixed $s)
{
    if ($s === null)
        return null;
    return html_entity_decode(trim($s));
}

/**
 * Generate an excerpt of a given content string with a specified character limit.
 * @param mixed $content The content string to generate an excerpt from.
 * @param int $limit (Optional) The character limit for the excerpt (default: 15).
 * @return string The excerpted content with an ellipsis (...) if truncated.
 */
function excerpt($content, int $limit = 15, string $ending = '...'): string
{
    $content = unClean($content);
    if (mb_strlen($content) <= $limit)
        return $content;
    return mb_substr($content, 0, $limit) . $ending;
}

/**
 * Extracts the first n words from a string.
 * 
 * @param string|null $content The source string (e.g., full name).
 * @param int $limit Number of words to extract.
 * @return string The truncated string or an empty string if input is null.
 */
function getFirstWords(?string $content, int $limit = 2): string
{
    if (empty(trim((string) $content))) {
        return '';
    }
    // Explode avec limite pour optimiser la mémoire, puis slice pour la précision
    $words = explode(' ', trim($content));
    $slice = array_slice($words, 0, $limit);

    return htmlspecialchars(implode(' ', $slice), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitizes the string (replaces special characters with '_').
 * 
 * @param string|null $name The original name
 * @return string|null The cleaned name
 */
function sanitizeName(string|null $name, string $replace = '_'): ?string
{
    if ($name == null)
        return null;
    $name = preg_replace('/[^a-zA-Z0-9_-]/', $replace, $name);
    return trim($name, $replace);
}

/**
 * Whitelist of CSS classes allowed to be persisted, regardless of which
 * WYSIWYG editor produced the HTML (TinyMCE, CKEditor, Quill, custom
 * UIEditor, etc.). Any class not in this list is stripped out
 * (e.g. mce-content-body, ck-widget, ql-editor, ProseMirror, ...).
 *
 * @return string[]
 */
function getAllowedHtmlClasses(): array
{
    return [
        'ui-editor-content',
        'h1', 'h2', 'h3', 'h4', 'h5',
        'p', 'blockquote', 'ul', 'ol', 'a',
        'leading-relaxed', 'font-bold', 'italic', 'underline', 'line-through',
    ];
}

/**
 * Builds (once) the configured HTMLPurifier instance.
 * Cached in a static variable to avoid rebuilding the config
 * on every call (expensive operation).
 *
 * @return HTMLPurifier
 */
function getHtmlPurifier(): HTMLPurifier
{
    static $purifier = null;

    if ($purifier === null) {
        $config = HTMLPurifier_Config::createDefault();

        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');

        $config->set(
            'HTML.Allowed',
            'h1,h2,h3,h4,h5,h6,p,div,span,blockquote,' .
            'ul,ol,li,' .
            'strong,b,em,i,u,s,strike,sub,sup,' .
            'a[href|title|target|rel],' .
            'br,hr,' .
            'table,thead,tbody,tr,th,td,' .
            'code,pre,' .
            'img[src|alt|width|height]'
        );

        $config->set('Attr.EnableID', false);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);

        $config->set('CSS.AllowedProperties', ''); // pas de style inline
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        $config->set('HTML.ForbiddenElements', 'script,style,iframe,object,embed,form,input,button');
        $config->set('HTML.ForbiddenAttributes', '*@on*'); // bloque onclick, onerror, ...

        $config->set('Core.RemoveInvalidImg', true);
        $config->set('Core.NormalizeNewlines', true);

        // cache des définitions HTMLPurifier (évite de regénérer à chaque requête)
        $cacheDir = __DIR__ . '/../../storage/htmlpurifier';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $config->set('Cache.SerializerPath', $cacheDir);

        $purifier = new HTMLPurifier($config);
    }

    return $purifier;
}

/**
 * Sanitizes "rich" HTML coming from any WYSIWYG editor:
 *  1. Strict purification (removes dangerous tags/attributes)
 *  2. CSS class filtering via whitelist (independent of the source editor)
 *
 * @param string|null $html                Raw HTML to sanitize
 * @param string[]    $extraAllowedClasses  Extra classes to allow for this call
 *
 * @return string|null Sanitized HTML, ready to be stored and rendered as-is ({!! !!} / direct echo)
 */
function sanitizeHtml(?string $html, array $extraAllowedClasses = []): ?string
{
    if ($html === null) {
        return null;
    }

    $html = trim($html);

    if ($html === '') {
        return null;
    }

    $clean = getHtmlPurifier()->purify($html);

    return filterHtmlClasses($clean, array_merge(getAllowedHtmlClasses(), $extraAllowedClasses));
}

/**
 * Walks the DOM and keeps, on the class attribute, only the values
 * present in the given whitelist.
 *
 * @param string   $html    Already-purified HTML
 * @param string[] $allowed Allowed classes
 *
 * @return string
 */
function filterHtmlClasses(string $html, array $allowed): string
{
    if (trim($html) === '') {
        return $html;
    }

    $dom = new \DOMDocument('1.0', 'UTF-8');
    $wrapped = '<?xml encoding="UTF-8"><div id="__root__">' . $html . '</div>';

    libxml_use_internal_errors(true);
    $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new \DOMXPath($dom);
    foreach ($xpath->query('//*[@class]') as $el) {
        /** @var \DOMElement $el */
        $classes = preg_split('/\s+/', trim($el->getAttribute('class')));
        $kept = array_values(array_intersect($classes, $allowed));

        if (empty($kept)) {
            $el->removeAttribute('class');
        } else {
            $el->setAttribute('class', implode(' ', $kept));
        }
    }

    $root = $dom->getElementById('__root__');
    $innerHtml = '';
    foreach ($root->childNodes as $child) {
        $innerHtml .= $dom->saveHTML($child);
    }

    return trim($innerHtml);
}