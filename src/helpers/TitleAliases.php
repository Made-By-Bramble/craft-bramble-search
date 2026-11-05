<?php

declare(strict_types=1);

namespace MadeByBramble\BrambleSearch\helpers;

final class TitleAliases
{
    public static function normalizeInput(string $input): ?string
    {
        if (preg_match('/\A\s*[\p{L}\p{N}]+(?:\s+[\p{L}\p{N}]+|\p{Pd}[\p{L}\p{N}]+)*\s*\z/uD', $input) !== 1
            || preg_match('/\b(?:OR|AND|NOT)\b/iu', $input) === 1) {
            return null;
        }
        $normalized = implode(' ', SearchQueryParser::tokenize($input));

        return $normalized === '' || mb_strlen($normalized, 'UTF-8') > 128 ? null : $normalized;
    }

    public static function compile(array $aliases, array $stopWords): array
    {
        if (count($aliases) > 32) {
            throw new \InvalidArgumentException('Title aliases support at most 32 input variants.');
        }
        $compiled = [];
        foreach ($aliases as $variant => $canonical) {
            $input = is_string($variant) ? self::normalizeInput($variant) : null;
            if ($input === null || !self::hasSpecificWord(SearchQueryParser::tokenize($input), $stopWords)
                || !is_array($canonical) || !array_is_list($canonical) || count($canonical) < 2 || count($canonical) > 4) {
                throw new \InvalidArgumentException('Each title alias needs a plain specific input and two to four canonical tokens.');
            }
            $tokens = [];
            foreach ($canonical as $token) {
                if (!is_string($token) || mb_strlen($token, 'UTF-8') < 1 || mb_strlen($token, 'UTF-8') > 64
                    || preg_match('/\A[\p{L}\p{N}]+\z/uD', $token) !== 1) {
                    throw new \InvalidArgumentException('Canonical title alias tokens must each be one alphanumeric token of at most 64 characters.');
                }
                $normalized = SearchQueryParser::tokenize($token);
                if (count($normalized) !== 1) {
                    throw new \InvalidArgumentException('A canonical title alias token must remain one search token.');
                }
                $tokens[] = $normalized[0];
            }
            if (count(array_unique($tokens)) !== count($tokens) || !self::hasSpecificWord($tokens, $stopWords)) {
                throw new \InvalidArgumentException('Canonical title aliases need distinct tokens and a specific alphabetic word.');
            }
            if (isset($compiled[$input]) && $compiled[$input] !== $tokens) {
                throw new \InvalidArgumentException('Normalized title alias inputs must not conflict.');
            }
            $compiled[$input] = $tokens;
        }

        return $compiled;
    }

    private static function hasSpecificWord(array $tokens, array $stopWords): bool
    {
        foreach ($tokens as $token) {
            if (preg_match('/\p{L}{2}/u', $token) === 1 && !in_array($token, $stopWords, true)) {
                return true;
            }
        }

        return false;
    }
}
