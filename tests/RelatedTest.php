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
function redirectOptions($options, $query) {
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
    $result = handler($request, $response, ['system' => '30000142', 'time' => '202609070900', 'options' => json_encode($options)], null);
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
