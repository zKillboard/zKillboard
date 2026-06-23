<?php

require_once __DIR__ . '/../init.php';

function fail($message)
{
    fwrite(STDERR, "Build legacy SDE compare smoke failed: $message\n");
    exit(1);
}

function loadJsonArray($source)
{
    $raw = @file_get_contents($source);
    if ($raw === false) fail("unable to read $source");

    $json = json_decode($raw, true);
    if (!is_array($json)) fail("invalid JSON from $source");

    return $json;
}

function normalizeLegacyBuildData($products, $materials)
{
    $reqsByBlueprint = [];
    foreach ($materials as $row) {
        if (($row['activityID'] ?? null) != 1) continue;
        if (!isset($row['typeID'], $row['materialTypeID'], $row['quantity'])) continue;

        $blueprintTypeID = (int) $row['typeID'];
        $materialTypeID = (int) $row['materialTypeID'];
        $quantity = (int) $row['quantity'];
        $reqsByBlueprint[$blueprintTypeID][$materialTypeID] = max(1, ceil(0.9 * $quantity));
    }

    $buildsByProduct = [];
    foreach ($products as $row) {
        if (($row['activityID'] ?? null) != 1) continue;
        if (!isset($row['typeID'], $row['productTypeID'], $row['quantity'])) continue;

        $blueprintTypeID = (int) $row['typeID'];
        $productTypeID = (int) $row['productTypeID'];
        $buildsByProduct[$productTypeID] = [
            'typeID' => $blueprintTypeID,
            'productTypeID' => $productTypeID,
            'quantity' => max(1, (int) $row['quantity']),
            'reqs' => $reqsByBlueprint[$blueprintTypeID] ?? [],
        ];
    }

    return $buildsByProduct;
}

function normalizeCurrentBuildData($rows)
{
    $normalizer = new ReflectionMethod('Build', 'normalize_blueprint');
    $normalizer->setAccessible(true);

    $buildsByProduct = [];
    foreach ($rows as $row) {
        $bp = $normalizer->invoke(null, $row);
        if ($bp == null) continue;

        $productTypeID = (int) $bp['productTypeID'];
        $reqs = $bp['reqs'];
        ksort($reqs);

        $buildsByProduct[$productTypeID] = [
            'typeID' => (int) $bp['typeID'],
            'productTypeID' => $productTypeID,
            'quantity' => max(1, (int) $bp['quantity']),
            'reqs' => $reqs,
        ];
    }

    return $buildsByProduct;
}

function summarizeBuild($build)
{
    return sprintf(
        'typeID=%d quantity=%d reqs=%s',
        $build['typeID'],
        $build['quantity'],
        json_encode($build['reqs'])
    );
}

function summarizeReqDiff($legacyReqs, $currentReqs)
{
    $added = array_diff_key($currentReqs, $legacyReqs);
    $removed = array_diff_key($legacyReqs, $currentReqs);
    $changed = [];

    foreach (array_intersect(array_keys($legacyReqs), array_keys($currentReqs)) as $typeID) {
        if ($legacyReqs[$typeID] != $currentReqs[$typeID]) {
            $changed[$typeID] = [
                'legacy' => $legacyReqs[$typeID],
                'current' => $currentReqs[$typeID],
            ];
        }
    }

    return sprintf(
        'added=%s removed=%s changed=%s',
        json_encode($added),
        json_encode($removed),
        json_encode($changed)
    );
}

function summarizeReqDiffCounts($legacyReqs, $currentReqs)
{
    $added = array_diff_key($currentReqs, $legacyReqs);
    $removed = array_diff_key($legacyReqs, $currentReqs);
    $changed = 0;

    foreach (array_intersect(array_keys($legacyReqs), array_keys($currentReqs)) as $typeID) {
        if ($legacyReqs[$typeID] != $currentReqs[$typeID]) {
            $changed++;
        }
    }

    return sprintf('reqs added=%d removed=%d changed=%d', sizeof($added), sizeof($removed), $changed);
}

function identifyMismatch($productTypeID, $legacyBuild, $currentBuild)
{
    $details = [];

    if ($legacyBuild['typeID'] != $currentBuild['typeID']) {
        $details[] = sprintf('typeID legacy=%d current=%d', $legacyBuild['typeID'], $currentBuild['typeID']);
    }

    if ($legacyBuild['quantity'] != $currentBuild['quantity']) {
        $details[] = sprintf('quantity legacy=%d current=%d', $legacyBuild['quantity'], $currentBuild['quantity']);
    }

    if ($legacyBuild['reqs'] != $currentBuild['reqs']) {
        $details[] = 'reqs ' . summarizeReqDiff($legacyBuild['reqs'], $currentBuild['reqs']);
    }

    return sprintf(
        'productTypeID=%d differences={%s} legacy[%s] current[%s]',
        $productTypeID,
        implode('; ', $details),
        summarizeBuild($legacyBuild),
        summarizeBuild($currentBuild)
    );
}

function identifyPriceDeltaReason($legacyBuild, $currentBuild)
{
    if ($legacyBuild == $currentBuild) {
        return 'same build requirements; price delta is from price lookup or rounding';
    }

    $reasons = [];
    if ($legacyBuild['typeID'] != $currentBuild['typeID']) {
        $reasons[] = sprintf('blueprint typeID mismatch old=%d new=%d', $legacyBuild['typeID'], $currentBuild['typeID']);
    }
    if ($legacyBuild['quantity'] != $currentBuild['quantity']) {
        $reasons[] = sprintf('output quantity mismatch old=%d new=%d', $legacyBuild['quantity'], $currentBuild['quantity']);
    }
    if ($legacyBuild['reqs'] != $currentBuild['reqs']) {
        $reasons[] = 'build requirements mismatch (' . summarizeReqDiffCounts($legacyBuild['reqs'], $currentBuild['reqs']) . ')';
    }

    return implode('; ', $reasons);
}

function getBuildMaterialPrice($build, $kmDate)
{
    $price = 0;
    foreach ($build['reqs'] as $typeID => $quantity) {
        $price += Price::getItemPrice($typeID, $kmDate) * $quantity;
    }

    return $price / max(1, $build['quantity']);
}

$productsSource = getenv('ZKB_LEGACY_PRODUCTS_JSON') ?: 'https://sde.zzeve.com/industryActivityProducts.json';
$materialsSource = getenv('ZKB_LEGACY_MATERIALS_JSON') ?: 'https://sde.zzeve.com/industryActivityMaterials.json';
$requestedComparisons = (int) (getenv('ZKB_BUILD_COMPARE_LIMIT') ?: 4700);
$maxAllowedMismatches = (int) (getenv('ZKB_BUILD_COMPARE_ALLOWED_MISMATCHES') ?: 20);
$maxMismatchDetails = (int) (getenv('ZKB_BUILD_COMPARE_MISMATCH_DETAILS') ?: 25);
$priceSampleChance = (int) (getenv('ZKB_BUILD_COMPARE_PRICE_SAMPLE_CHANCE') ?: 10);
$priceDate = getenv('ZKB_BUILD_COMPARE_PRICE_DATE') ?: date('Y-m-d');

if ($requestedComparisons <= 0) fail('ZKB_BUILD_COMPARE_LIMIT must be greater than zero');
if ($maxAllowedMismatches < 0) fail('ZKB_BUILD_COMPARE_ALLOWED_MISMATCHES cannot be negative');
if ($maxMismatchDetails < 0) fail('ZKB_BUILD_COMPARE_MISMATCH_DETAILS cannot be negative');
if ($priceSampleChance <= 0) fail('ZKB_BUILD_COMPARE_PRICE_SAMPLE_CHANCE must be greater than zero');

$legacy = normalizeLegacyBuildData(loadJsonArray($productsSource), loadJsonArray($materialsSource));
if (sizeof($legacy) === 0) fail('legacy build data produced no manufacturing products');

$rows = $mdb->find('sde_blueprints');
$current = normalizeCurrentBuildData($rows);
if (sizeof($current) === 0) fail('current sde_blueprints data produced no manufacturing products');

$overlap = array_values(array_intersect(array_keys($legacy), array_keys($current)));
sort($overlap, SORT_NUMERIC);

$legacyOnly = array_values(array_diff(array_keys($legacy), array_keys($current)));
$currentOnly = array_values(array_diff(array_keys($current), array_keys($legacy)));
sort($legacyOnly, SORT_NUMERIC);
sort($currentOnly, SORT_NUMERIC);

if (sizeof($overlap) < $requestedComparisons) {
    fail(sprintf(
        'only %d comparable distinct product types are available; requested %d (legacy=%d current=%d)',
        sizeof($overlap),
        $requestedComparisons,
        sizeof($legacy),
        sizeof($current)
    ));
}

$checked = 0;
$mismatches = [];
$priceSamples = 0;
echo "Using build price date: $priceDate\n";
foreach (array_slice($overlap, 0, $requestedComparisons) as $productTypeID) {
    $checked++;

    $legacyBuild = $legacy[$productTypeID];
    $currentBuild = $current[$productTypeID];
    ksort($legacyBuild['reqs']);
    ksort($currentBuild['reqs']);

    if (mt_rand(1, $priceSampleChance) == 1) {
        $legacyPrice = getBuildMaterialPrice($legacyBuild, $priceDate);
        $currentPrice = getBuildMaterialPrice($currentBuild, $priceDate);
        $delta = $currentPrice - $legacyPrice;
        $priceSamples++;
        $reason = abs($delta) >= 0.005 ? ' reason="' . identifyPriceDeltaReason($legacyBuild, $currentBuild) . '"' : '';

        echo sprintf(
            "Price sample date=%s productTypeID=%d oldBlueprint=%d newBlueprint=%d old=%s new=%s delta=%s%s\n",
            $priceDate,
            $productTypeID,
            $legacyBuild['typeID'],
            $currentBuild['typeID'],
            number_format($legacyPrice, 2),
            number_format($currentPrice, 2),
            number_format($delta, 2),
            $reason
        );
    }

    if ($legacyBuild != $currentBuild) {
        $mismatches[] = identifyMismatch($productTypeID, $legacyBuild, $currentBuild);
    }
}

if (sizeof($mismatches) > $maxAllowedMismatches) {
    fail(sprintf(
        "%d mismatches found while comparing %d product types; allowed %d",
        sizeof($mismatches),
        $checked,
        $maxAllowedMismatches
    ));
}

echo sprintf(
    "Build legacy SDE compare smoke passed: date=%s, compared %d distinct product types, priceSamples=%d, mismatches=%d, legacy=%d, current=%d, overlap=%d, legacyOnly=%d, currentOnly=%d\n",
    $priceDate,
    $checked,
    $priceSamples,
    sizeof($mismatches),
    sizeof($legacy),
    sizeof($current),
    sizeof($overlap),
    sizeof($legacyOnly),
    sizeof($currentOnly)
);
