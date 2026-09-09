<?php

// Standalone summary checks; no database, Redis, or network access.
class Info {
    public static function addInfo(&$data) {}
    public static function getInfoField($type, $id, $field) {
        if ($field == 'mass') return 1;
        if ($type == 'allianceID' && $id == 400) return null;
        return "Entity $id";
    }
}
class Util {
    public static function iskToUsdEurGbp($value) { return []; }
}
require_once __DIR__ . '/../classes/Related.php';

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$pilots = [];
foreach ([100, 200, 300, 400] as $id) {
    $pilots[$id] = ['characterID' => $id + 1000, 'allianceID' => $id == 400 ? 0 : $id, 'corporationID' => $id == 400 ? 400 : $id + 10, 'shipTypeID' => 587, 'groupID' => 25];
}
$kills = [];
foreach ($pilots as $id => $pilot) {
    $victim = $pilot + ['killID' => $id];
    $kills[$id] = ['victim' => $victim, 'involved' => array_merge([$victim], array_values(array_diff_key($pilots, [$id => true]))), 'zkb' => ['totalValue' => $id, 'droppedValue' => 0, 'points' => 1]];
}
$options = ['A' => [100], 'B' => [200], 'C' => [300], 'excluded' => [400]];
$summary = Related::buildSummary($kills, $options);
foreach (['A' => 100, 'B' => 200, 'C' => 300] as $side => $id) {
    $team = $summary['team' . $side];
    check(array_keys($team['entities']) === [$id], "Wrong entities on $side");
    check(array_keys($team['kills']) === [$id], "Wrong losses on $side");
    check($team['totals']['total_price'] === $id, "Wrong ISK on $side");
    check($team['totals']['pilotCount'] === 1, "Wrong pilot count on $side");
    check(count($team['totals']['groupIDs'][25]['fielded']) === 1, "Other sides leaked into fielded ships on $side");
}
check(array_keys($summary['excluded']) === [400], 'Excluded corporation is missing');
check($summary['excluded'][400]['type'] === 'corporation', 'Zero alliance ID must fall back to corporation');
$restored = Related::buildSummary($kills, ['A' => [100], 'B' => [200], 'C' => [300], 'D' => [400]]);
check($restored['teamD']['totals']['total_price'] === 400 && !$restored['excluded'], 'Restore to fourth side failed');
$conflict = Related::buildSummary($kills, ['A' => [100, 300], 'C' => [300], 'excluded' => [100]]);
check(!isset($conflict['teamA']['entities'][100]) && !isset($conflict['teamA']['entities'][300]), 'Conflicting assignments duplicated entities');
check(isset($conflict['teamC']['entities'][300], $conflict['excluded'][100]), 'Assignment precedence failed');
$excluded = Related::buildSummary($kills, ['excluded' => array_keys($pilots)]);
check($excluded['teamA']['totals']['total_price'] === 0 && $excluded['teamB']['totals']['pilotCount'] === 0, 'Excluding everyone must zero totals');
check(count($excluded['excluded']) === 4, 'All excluded entities must remain restorable');
$unknown = Related::buildSummary($kills, ['C' => [9999]]);
check(!isset($unknown['teamC']), 'Unknown entity created an empty side');
$empty = [];
check(Related::buildSummary($empty, [])['teamA']['totals']['totalShips'] === 0, 'Empty report failed');
check(Related::normalizeOptions(['C' => [300, 300, [], -1, 'bad'], 'bad' => [1]]) === ['A' => [], 'B' => [], 'C' => [300], 'excluded' => []], 'Invalid options were not filtered');
check(Related::buildSummary($kills, json_decode(json_encode($options), true)) === $summary, 'Options JSON round trip changed the report');
echo "Related summary checks passed.\n";

require_once __DIR__ . '/../view/related.php';
function redirectOptions($options, $query, $system = '30000142') {
    $request = new class($query) {
        private $query;
        public function __construct($query) { $this->query = $query; }
        public function getQueryParams() { return $this->query; }
    };
    $response = new class {
        public $location;
        public $status;
        public function withHeader($name, $value) { $this->location = $value; return $this; }
        public function withStatus($status) { $this->status = $status; return $this; }
    };
    $result = handler($request, $response, ['system' => $system, 'time' => '202609070900', 'options' => json_encode($options)], null);
    check($result->status === 302, 'Entity reassignment did not redirect');
    return json_decode(urldecode(explode('/o/', rtrim($result->location, '/'))[1]), true);
}
$moved = redirectOptions($options, ['entity' => '300', 'side' => 'A']);
check(in_array(300, $moved['A']) && !in_array(300, $moved['C']), 'Moving between sides did not remove the old assignment');
$removed = redirectOptions($options, ['entity' => '300', 'side' => 'excluded']);
check(in_array(300, $removed['excluded']) && !$removed['C'], 'Exclusion redirect retained the old side');
$added = redirectOptions($options, ['entity' => '400', 'side' => 'D']);
check($added['D'] === [400] && !$added['excluded'], 'Restoring an entity did not clear its exclusion');
echo "Related reassignment checks passed.\n";

// Gate fixtures exercise connected selections without database access.
$mdb = new class {
    public function find($collection, $query) {
        $id = $query['solarSystemID'];
        $destinations = $id == 1 ? [2, 3] : ($id == 2 ? [1, 4] : ($id == 4 ? [2, 5] : []));
        if ($id >= 10 && $id < 25) $destinations = [$id + 1];
        return array_map(function ($destination) { return ['destination' => ['solarSystemID' => $destination]]; }, $destinations);
    }
};
check(Related::getSystems(1, []) === ['selected' => [1], 'adjacent' => [2, 3]], 'Starting system neighbors missing');
check(Related::getSystems(1, [5, 4, 2])['selected'] === [2, 4, 5], 'Selected systems must be retained without the original system');
check(Related::getSystems(1, [1, 4, 5])['selected'] === [1, 4, 5], 'Removing a connecting system must preserve the remaining systems');
check(count(Related::getSystems(10, range(10, 25))['selected']) === 10, 'System limit was not enforced');
$withSystems = $options + ['systems' => [1, 2, 4]];
check(Related::buildSummary($kills, $withSystems) === $summary, 'Systems changed team assignments');
check(redirectOptions($withSystems, ['entity' => '300', 'side' => 'A'])['systems'] === [1, 2, 4], 'Team changes lost selected systems');
check(Related::normalizeOptions(['systems' => [1, '1', [], 'bad', -1, 2]])['systems'] === [1, 2], 'Invalid or duplicate systems survived normalization');
check(count(Related::normalizeOptions(['systems' => range(1, 20)])['systems']) === 10, 'Options exceeded system limit');
check(redirectOptions($withSystems, ['addSystem' => '5'], '1')['systems'] === [1, 2, 4, 5], 'Adding an adjacent system failed');
check(redirectOptions($withSystems, ['addSystem' => '999'], '1')['systems'] === [1, 2, 4], 'Unconnected system was added');
check(redirectOptions($withSystems, ['removeSystem' => '2'], '1')['systems'] === [1, 4], 'Removing a connecting system removed other systems');
check(redirectOptions($withSystems, ['removeSystem' => '1'], '1')['systems'] === [2, 4], 'Starting system could not be removed');

require_once __DIR__ . '/../classes/MongoFilter.php';
$parameters = ['solarSystemID' => [1, 2, 4]];
$query = MongoFilter::buildQuery($parameters);
check(str_contains(json_encode($query), '"system.solarSystemID":{"$in":[1,2,4]}'), 'Report query did not include all selected systems');
echo "Related system checks passed.\n";

check(redirectOptions(['systems' => [1]], ['removeSystem' => '1'], '1')['systems'] === [1], 'Last system was removed');
check(redirectOptions(['systems' => [1, 2]], ['removeSystem' => '1'], '1')['systems'] === [2], 'Removing the starting system did not retain the last system');

$timeOptions = $withSystems + ['hoursBefore' => 12, 'hoursAfter' => 7];
check(Related::buildSummary($kills, $timeOptions) === $summary, 'Time options changed team assignments');
check(redirectOptions($timeOptions, ['entity' => '300', 'side' => 'A'])['hoursBefore'] === 12, 'Team changes lost time settings');
check(redirectOptions($timeOptions, ['removeSystem' => '2'], '1')['hoursAfter'] === 7, 'System changes lost time settings');
$changedTime = redirectOptions($timeOptions, ['hoursBefore' => '4', 'hoursAfter' => '12']);
check($changedTime['hoursBefore'] === 4 && $changedTime['hoursAfter'] === 12 && $changedTime['systems'] === [1, 2, 4] && $changedTime['C'] === [300], 'Time changes lost report options');
check(!isset(Related::normalizeOptions(['hoursBefore' => [], 'hoursAfter' => 'bad'])['hoursBefore']), 'Malformed time options survived');
check(Related::normalizeOptions(['hoursBefore' => 999, 'hoursAfter' => 999])['hoursAfter'] === 12, 'Time limit was not enforced');
check(Related::normalizeOptions(['hoursBefore' => 1, 'hoursAfter' => 2]) === Related::normalizeOptions([]), 'Default window changed cache options');

// Capture the queued report parameters without Redis or database writes.
class MongoQueue {
    public function __construct($mdb, $collection, $flag) {}
    public function push($key) {}
}
require_once __DIR__ . '/../classes/RelatedReport.php';
$mdb = new class {
    public function find($collection, $query) { return []; }
    public function findDoc($collection, $query) { return ['name' => 'System', 'regionID' => 1]; }
};
foreach ([[], ['hoursBefore' => 12, 'hoursAfter' => 12], ['hoursBefore' => 4, 'hoursAfter' => 7]] as $window) {
    $redis = new class {
        public $parameters;
        public function get($key) { return $key == 'zkb:reinforced' ? false : ($this->parameters ? serialize([]) : ''); }
        public function llen($key) { return 0; }
        public function setex($key, $ttl, $value) { $this->parameters = unserialize($value); }
        public function setnx($key, $value) { return true; }
        public function expire($key, $ttl) {}
    };
    $report = RelatedReport::generateReport(1, '202609090100', json_encode($window));
    $anchor = strtotime('2026-09-09 01:00');
    check(strtotime($redis->parameters['startTime']) === $anchor - 3600 * ($window['hoursBefore'] ?? 1), 'Incorrect queued start time');
    check(strtotime($redis->parameters['endTime']) === $anchor + 3600 * ($window['hoursAfter'] ?? 2), 'Incorrect queued end time');
    check(!isset($redis->parameters['relatedTime']), 'Original time filter would restrict the expanded window');
    check($report['startTime'] . ' UTC' === $redis->parameters['startTime'] && $report['endTime'] . ' UTC' === $redis->parameters['endTime'], 'Displayed time window differs from query');
}
echo "Related time window checks passed.\n";
