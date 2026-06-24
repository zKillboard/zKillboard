<?php

class DailyStatsCharts
{
    public static function labelRadarCharts($rows)
    {
        $groups = self::labelGroups($rows);
        self::addUnderOneBillionIskBand($groups);

        $charts = [];
        foreach ($groups as $group) {
            usort($group['rows'], function ($a, $b) {
                return (($b['count'] ?? 0) <=> ($a['count'] ?? 0)) ?: (($b['isk'] ?? 0) <=> ($a['isk'] ?? 0));
            });

            $totalCount = array_sum(array_map(function ($row) { return (int) ($row['count'] ?? 0); }, $group['rows']));
            $totalIsk = array_sum(array_map(function ($row) { return (double) ($row['isk'] ?? 0); }, $group['rows']));
            $rows = array_values($group['rows']);
            if (count($rows) > 12) {
                $other = ['label' => 'other', 'axisLabel' => 'other', 'count' => 0, 'isk' => 0];
                foreach (array_slice($rows, 11) as $row) {
                    $other['count'] += (int) ($row['count'] ?? 0);
                    $other['isk'] += (double) ($row['isk'] ?? 0);
                }
                $rows = array_merge(array_slice($rows, 0, 11), [$other]);
            }
            $charts[] = self::radarChart($group['title'], $rows, $totalCount, $totalIsk);
        }

        usort($charts, function ($a, $b) {
            return ($b['totalCount'] ?? 0) <=> ($a['totalCount'] ?? 0);
        });
        return $charts;
    }

    private static function labelGroups($rows)
    {
        $titles = ['loc' => 'Locations', 'tz' => 'Time Zones', 'cat' => 'Categories', 'isk' => 'ISK Bands', '#' => 'Attacker Counts', 'fw' => 'Faction Warfare'];
        $groups = [];
        foreach ((array) $rows as $row) {
            $row = is_object($row) ? (array) $row : (array) $row;
            $label = (string) ($row['label'] ?? '');
            if ($label == '') {
                continue;
            }

            $pos = strpos($label, ':');
            $groupKey = $pos === false ? '_labels' : substr($label, 0, $pos);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'title' => $groupKey == '_labels' ? 'Labels' : ($titles[$groupKey] ?? strtoupper($groupKey) . ' Labels'),
                    'rows' => [],
                ];
            }
            $row['axisLabel'] = self::axisLabel($groupKey, $label, $pos === false ? $label : (substr($label, $pos + 1) ?: $label));
            $groups[$groupKey]['rows'][] = $row;
        }
        return $groups;
    }

    private static function axisLabel($groupKey, $label, $axisLabel)
    {
        if ($groupKey == 'cat' && preg_match('/^cat:(\d+)$/', $label, $matches)) {
            $name = Info::getInfoField('categoryID', (int) $matches[1], 'name');
            if ($name != null && $name != '') {
                return $name;
            }

            return AdvancedSearch::$labels['custom'][$label] ?? $axisLabel;
        }

        return $axisLabel;
    }

    private static function addUnderOneBillionIskBand(&$groups)
    {
        $pvpRow = null;
        foreach (($groups['_labels']['rows'] ?? []) as $row) {
            if (($row['label'] ?? '') == 'pvp') {
                $pvpRow = $row;
                break;
            }
        }
        if ($pvpRow == null) {
            return;
        }

        $groups['isk'] = $groups['isk'] ?? ['title' => 'ISK Bands', 'rows' => []];
        $knownCount = 0;
        $knownIsk = 0;
        foreach ($groups['isk']['rows'] as $row) {
            $knownCount += (int) ($row['count'] ?? 0);
            $knownIsk += (double) ($row['isk'] ?? 0);
        }

        $underCount = max(0, (int) ($pvpRow['count'] ?? 0) - $knownCount);
        $underIsk = max(0, (double) ($pvpRow['isk'] ?? 0) - $knownIsk);
        if ($underCount > 0 || $underIsk > 0) {
            $groups['isk']['rows'][] = ['label' => 'isk:<1b', 'axisLabel' => '<1b', 'count' => $underCount, 'isk' => $underIsk];
        }
    }

    private static function radarChart($title, $rows, $totalCount, $totalIsk)
    {
        $center = 150;
        $radius = 82;
        $labelRadius = 108;
        $count = count($rows);
        $max = max(1, max(array_map(function ($row) { return (float) ($row['count'] ?? 0); }, $rows ?: [[]])));
        $points = [];
        $axes = [];
        $grids = [];
        foreach ([0.25, 0.5, 0.75, 1] as $gridScale) {
            $grid = [];
            for ($idx = 0; $idx < max(3, $count); $idx++) {
                $angle = (2 * M_PI * $idx / max(3, $count)) - (M_PI / 2);
                $grid[] = round($center + cos($angle) * $radius * $gridScale, 2) . ',' . round($center + sin($angle) * $radius * $gridScale, 2);
            }
            $grids[] = implode(' ', $grid);
        }

        foreach ($rows as $idx => $row) {
            $angle = (2 * M_PI * $idx / max(3, $count)) - (M_PI / 2);
            $scale = min(1, ((float) ($row['count'] ?? 0)) / $max);
            $points[] = round($center + cos($angle) * $radius * $scale, 2) . ',' . round($center + sin($angle) * $radius * $scale, 2);
            $axes[] = [
                'x2' => round($center + cos($angle) * $radius, 2),
                'y2' => round($center + sin($angle) * $radius, 2),
                'labelX' => round($center + cos($angle) * $labelRadius, 2),
                'labelY' => round($center + sin($angle) * $labelRadius, 2),
                'anchor' => cos($angle) > 0.25 ? 'start' : (cos($angle) < -0.25 ? 'end' : 'middle'),
                'label' => (string) ($row['axisLabel'] ?? $row['label'] ?? ''),
                'fullLabel' => (string) ($row['label'] ?? ''),
                'count' => (int) ($row['count'] ?? 0),
                'isk' => (double) ($row['isk'] ?? 0),
                'percent' => $totalCount > 0 ? (((int) ($row['count'] ?? 0)) / $totalCount) * 100 : 0,
                'pointX' => round($center + cos($angle) * $radius * $scale, 2),
                'pointY' => round($center + sin($angle) * $radius * $scale, 2),
            ];
        }

        return [
            'title' => $title,
            'points' => implode(' ', $points),
            'axes' => $axes,
            'grids' => $grids,
            'totalCount' => $totalCount,
            'totalIsk' => $totalIsk,
        ];
    }
}
