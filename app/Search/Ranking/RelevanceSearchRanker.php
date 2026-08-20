<?php

declare(strict_types=1);

namespace App\Search\Ranking;

use App\Search\Contracts\SearchRankerInterface;
use App\Search\SearchQuery;
use App\Search\SearchResult;

/**
 * Initial relevance ranker (Phase 3.1.6N-C): normalize each hit's score against
 * the global maximum, then sort by that normalized score, descending, with a
 * deterministic (type, id) tie-break so results are stable across runs.
 *
 * Normalization is what lets scores from DIFFERENT providers (different natural
 * scales) be combined into one ordered list. No semantic, AI, or vector ranking.
 */
final class RelevanceSearchRanker implements SearchRankerInterface
{
    public function rank(array $results, SearchQuery $query): array
    {
        if ($results === []) {
            return [];
        }

        $max = 0.0;
        foreach ($results as $result) {
            if ($result->score > $max) {
                $max = $result->score;
            }
        }

        $sorted = $results;
        usort($sorted, static function (SearchResult $a, SearchResult $b) use ($max): int {
            $na = $max > 0.0 ? $a->score / $max : 0.0;
            $nb = $max > 0.0 ? $b->score / $max : 0.0;

            if ($na !== $nb) {
                return $nb <=> $na; // Higher normalized score first.
            }

            if ($a->type !== $b->type) {
                return $a->type <=> $b->type;
            }

            return (string) $a->id <=> (string) $b->id;
        });

        return array_values($sorted);
    }
}
