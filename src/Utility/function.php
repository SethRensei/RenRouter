<?php

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
function unclean(mixed $s)
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
function sanitizeName(string|null $name): ?string
{
    if ($name == null)
        return null;
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    return trim($name, '_');
}