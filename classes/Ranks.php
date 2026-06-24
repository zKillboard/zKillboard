<?php

class Ranks
{
    const PAGE_SIZE = 500;

    public static function exists($epoch, $scope, $type)
    {
        global $mdb;

        return $mdb->exists('statistics', ['type' => $type, "rankings.$epoch.$scope" => ['$exists' => true]]);
    }

    public static function getRow($epoch, $scope, $type, $id, $date = null)
    {
        global $mdb;

        $doc = $mdb->findDoc('statistics', ['type' => $type, 'id' => self::normalizeId($id)]);
        if ($doc == null) return null;

        if ($date != null) {
            return $doc['rankHistory'][$epoch][$scope][$date] ?? null;
        }

        return $doc['rankings'][$epoch][$scope] ?? null;
    }

    public static function getPage($epoch, $scope, $type, $sortKey, $sortDir, $page, $date = null)
    {
        global $mdb;

        $field = self::sortField($epoch, $scope, $sortKey);
        $ids = [];
        $rows = $mdb->find(
            'statistics',
            ['type' => $type, $field => ['$exists' => true]],
            [$field => $sortDir == 'asc' ? 1 : -1],
            self::PAGE_SIZE + 1,
            ['id' => 1, "rankings.$epoch.$scope" => 1],
            max(0, ($page - 1) * self::PAGE_SIZE)
        );

        $rankRows = [];
        foreach ($rows as $row) {
            $ids[] = $row['id'];
            $rankRows[$row['id']] = $row['rankings'][$epoch][$scope] ?? null;
        }

        $hasMore = sizeof($ids) > self::PAGE_SIZE ? 'y' : 'n';
        $ids = array_slice($ids, 0, self::PAGE_SIZE);
        $rankRows = array_intersect_key($rankRows, array_flip($ids));
        return ['ids' => $ids, 'rows' => $rankRows, 'hasMore' => $hasMore];
    }

    public static function rank($epoch, $scope, $type, $id, $metric = 'overall', $date = null)
    {
        $row = self::getRow($epoch, $scope, $type, $id, $date);
        if ($row == null) return null;

        return $row['ranks'][$metric] ?? null;
    }

    public static function score($epoch, $scope, $type, $id, $metric = 'overall', $date = null)
    {
        $row = self::getRow($epoch, $scope, $type, $id, $date);
        if ($row == null) return 0;

        if ($metric == 'overall') return $row['overallScore'] ?? 0;
        return $row['metrics'][$metric] ?? 0;
    }

    public static function nearby($epoch, $scope, $type, $id, $radius = 25)
    {
        global $mdb;

        $row = self::getRow($epoch, $scope, $type, $id);
        if ($row == null || !isset($row['ranks']['overall'])) return [];

        $rank = (int) $row['ranks']['overall'];
        $rankField = self::sortField($epoch, $scope, 'overallRank');
        $rows = $mdb->find(
            'statistics',
            [
                'type' => $type,
                $rankField => ['$gte' => max(1, $rank - $radius), '$lte' => $rank + $radius],
            ],
            [$rankField => 1],
            ($radius * 2) + 1,
            ['id' => 1, "rankings.$epoch.$scope" => 1]
        );

        $nearby = [];
        foreach ($rows as $doc) {
            $nearId = $doc['id'];
            $nearRow = $doc['rankings'][$epoch][$scope] ?? null;
            if ($nearRow == null) continue;
            $nearby[] = [
                'rank' => $nearRow['ranks']['overall'] ?? '-',
                $type => $nearId,
                'score' => $nearRow['overallScore'] ?? 0,
            ];
        }

        return $nearby;
    }

    private static function normalizeId($id)
    {
        return is_numeric($id) ? (int) $id : $id;
    }

    private static function sortField($epoch, $scope, $sortKey)
    {
        $metrics = [
            'shipsDestroyed' => 'shipsDestroyed',
            'shipsLost' => 'shipsLost',
            'pointsDestroyed' => 'pointsDestroyed',
            'pointsLost' => 'pointsLost',
            'iskDestroyed' => 'iskDestroyed',
            'iskLost' => 'iskLost',
        ];
        $ranks = [
            'overallRank' => 'overall',
            'sdRank' => 'shipsDestroyed',
            'slRank' => 'shipsLost',
            'pdRank' => 'pointsDestroyed',
            'plRank' => 'pointsLost',
            'idRank' => 'iskDestroyed',
            'ilRank' => 'iskLost',
        ];

        if (isset($metrics[$sortKey])) return "rankings.$epoch.$scope.metrics.{$metrics[$sortKey]}";
        return "rankings.$epoch.$scope.ranks." . ($ranks[$sortKey] ?? 'overall');
    }
}
