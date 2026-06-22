<?php

namespace MadeByBramble\BrambleSearch\helpers;

use craft\search\SearchQuery;
use craft\search\SearchQueryTerm;
use craft\search\SearchQueryTermGroup;

/**
 * Parses Craft search queries into Bramble's AND-group / OR-term structure.
 */
final class SearchQueryParser
{
    /**
     * @return array{andGroups: list<array{terms: list<array<string, mixed>}>>, excludeTerms: list<array<string, mixed>>, rawQuery: string}
     */
    public static function parse(string|SearchQuery|null $search): array
    {
        if ($search instanceof SearchQuery) {
            return self::parseSearchQueryObject($search);
        }

        $rawQuery = (string)$search;

        return self::parsePlainQuery($rawQuery);
    }

    /**
     * @return array{andGroups: list<array{terms: list<array<string, mixed>}>>, excludeTerms: list<array<string, mixed>>, rawQuery: string}
     */
    private static function parseSearchQueryObject(SearchQuery $search): array
    {
        $andGroups = [];
        $excludeTerms = [];

        foreach ($search->getTokens() as $token) {
            if ($token instanceof SearchQueryTermGroup) {
                $groupTerms = [];
                foreach ($token->terms as $term) {
                    if ($term->exclude) {
                        $excludeTerms[] = self::serializeTerm($term);
                        continue;
                    }
                    $groupTerms[] = self::serializeTerm($term);
                }
                if (!empty($groupTerms)) {
                    $andGroups[] = ['terms' => $groupTerms];
                }
                continue;
            }

            if ($token->exclude) {
                $excludeTerms[] = self::serializeTerm($token);
                continue;
            }

            $andGroups[] = ['terms' => [self::serializeTerm($token)]];
        }

        return [
            'andGroups' => $andGroups,
            'excludeTerms' => $excludeTerms,
            'rawQuery' => $search->getQuery(),
        ];
    }

    /**
     * @return array{andGroups: list<array{terms: list<array<string, mixed>}>>, excludeTerms: list<array<string, mixed>>, rawQuery: string}
     */
    private static function parsePlainQuery(string $rawQuery): array
    {
        $andGroups = [];
        $excludeTerms = [];

        foreach (preg_split('/\s+/u', trim($rawQuery), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if ($token === 'OR') {
                continue;
            }

            if (str_starts_with($token, '-')) {
                $term = mb_substr($token, 1);
                if ($term !== '') {
                    $excludeTerms[] = self::serializePlainTerm($term);
                }
                continue;
            }

            $andGroups[] = ['terms' => [self::serializePlainTerm($token)]];
        }

        return [
            'andGroups' => $andGroups,
            'excludeTerms' => $excludeTerms,
            'rawQuery' => $rawQuery,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeTerm(SearchQueryTerm $term): array
    {
        return [
            'term' => (string)$term->term,
            'attribute' => $term->attribute ? strtolower($term->attribute) : null,
            'subLeft' => (bool)$term->subLeft,
            'subRight' => (bool)$term->subRight,
            'exact' => (bool)$term->exact,
            'phrase' => (bool)$term->phrase,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializePlainTerm(string $token): array
    {
        if (preg_match('/^(\w+)(::?)(.+)$/', $token, $match)) {
            [, $attribute, $colons, $term] = $match;

            return [
                'term' => $term,
                'attribute' => strtolower($attribute),
                'subLeft' => false,
                'subRight' => $colons !== '::',
                'exact' => $colons === '::',
                'phrase' => false,
            ];
        }

        return [
            'term' => $token,
            'attribute' => null,
            'subLeft' => false,
            'subRight' => false,
            'exact' => false,
            'phrase' => false,
        ];
    }
}
