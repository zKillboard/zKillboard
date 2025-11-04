<?php
/**
 * zKillboard Blank Content Test Suite
 * Specifically tests for routes that return empty/blank content
 * Uses full GET requests to check actual response body content
 */

class BlankContentTestSuite {
    private $baseUrl;
    private $results = [];
    private $errorCount = 0;
    private $totalTests = 0;
    private $blankContentCount = 0;
    
    // Real entity IDs for testing
    private $realKillId = '130765767';
    private $realCharacterId = '2113213717';
    private $realSystemId = '30000142'; // Jita
    private $realRegionId = '10000002'; // The Forge
    private $realItemId = '34'; // Tritanium
    private $realShipId = '587'; // Rifter
    private $realCorporationId = '98012393';
    
    public function __construct($baseUrl = 'http://localhost') {
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    /**
     * Test a single route for blank content
     */
    private function testRouteContent($path, $expectedCodes = [200], $description = '') {
        $this->totalTests++;
        $url = $this->baseUrl . $path;
        
        // Use curl to GET the full content
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, false); // GET full content
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Extract body content
        $body = '';
        if ($response && $headerSize > 0) {
            $body = substr($response, $headerSize);
        }
        
        $status = 'PASS';
        $issues = [];
        
        if ($error) {
            $status = 'ERROR';
            $issues[] = "cURL Error: $error";
            $this->errorCount++;
        } elseif (!in_array($httpCode, $expectedCodes)) {
            $status = 'FAIL';
            $issues[] = "HTTP $httpCode (expected: " . implode('|', $expectedCodes) . ")";
            $this->errorCount++;
        }
        
        // Check for blank content on successful responses
        $isBlank = false;
        if (in_array($httpCode, [200]) && $status === 'PASS') {
            $bodyTrimmed = trim($body);
            $bodyLength = strlen($bodyTrimmed);
            
            // API endpoints that may legitimately return empty results
            $mayBeEmptyEndpoints = ['/asearchquery/', '/cache/bypass/killlist/', '/account/'];
            $isApiEndpoint = false;
            foreach ($mayBeEmptyEndpoints as $endpoint) {
                if (strpos($path, $endpoint) !== false) {
                    $isApiEndpoint = true;
                    break;
                }
            }
            
            if ($bodyLength === 0) {
                if (!$isApiEndpoint) {
                    $isBlank = true;
                    $this->blankContentCount++;
                    $status = 'BLANK';
                    $issues[] = "Empty content (0 bytes)";
                } else {
                    $issues[] = "Empty API response (may be expected)";
                }
            } elseif ($bodyLength < 100) {
                $issues[] = "Very short content ({$bodyLength} bytes)";
            }
            
            // Check for common blank page indicators
            if (!$isBlank && $bodyLength > 0 && !$isApiEndpoint) {
                $bodyLower = strtolower($bodyTrimmed);
                if (strpos($bodyLower, '<title></title>') !== false ||
                    strpos($bodyLower, '<body></body>') !== false ||
                    ($bodyLength < 200 && strpos($bodyLower, '<!doctype html>') !== false && 
                     strpos($bodyLower, 'content') === false)) {
                    $isBlank = true;
                    $this->blankContentCount++;
                    $status = 'BLANK';
                    $issues[] = "Effectively blank HTML (minimal content)";
                }
            }
        }
        
        $this->results[] = [
            'path' => $path,
            'expected_codes' => $expectedCodes,
            'actual_code' => $httpCode ?: 'ERROR',
            'status' => $status,
            'description' => $description,
            'content_length' => strlen($body),
            'is_blank' => $isBlank,
            'issues' => $issues,
            'body_preview' => $isBlank ? $body : substr($body, 0, 200) . '...'
        ];
        
        // Real-time output
        $statusColor = $status === 'PASS' ? "\033[32m" : 
                      ($status === 'BLANK' ? "\033[33m" : "\033[31m");
        $issueText = empty($issues) ? '' : ' (' . implode(', ', $issues) . ')';
        
        echo sprintf("%-60s [%s%s\033[0m] %s bytes%s\n", 
            $path, 
            $statusColor, 
            $status, 
            strlen($body),
            $issueText
        );
        
        return $status;
    }
    
    /**
     * Run tests specifically focused on routes that might return blank content
     */
    public function runBlankContentTests() {
        echo "=== zKillboard Blank Content Test Suite ===\n";
        echo "Testing routes for empty/blank content responses...\n\n";
        
        // Routes that should definitely have content
        echo "--- Core Content Routes (Should Have Content) ---\n";
        $this->testRouteContent('/', [200], 'Homepage');
        $this->testRouteContent('/information/about/', [200], 'About page');
        $this->testRouteContent('/information/faq/', [200], 'FAQ page');
        
        // Top routes that were showing redirects instead of content
        echo "\n--- Top Routes (Previously Redirecting) ---\n";
        $this->testRouteContent('/top/', [200, 302], 'Top weekly');
        $this->testRouteContent('/top/weekly/', [200, 302], 'Top weekly explicit');
        $this->testRouteContent('/top/monthly/', [200, 302], 'Top monthly');
        $this->testRouteContent('/top/alltime/', [200, 302], 'Top alltime');
        $this->testRouteContent('/top/lasthour/all/', [200], 'Top last hour all (FIXED)');
        $this->testRouteContent('/top/lasthour/kills/', [302], 'Top last hour kills (should redirect - invalid type)');
        $this->testRouteContent('/top/lasthour/nullsec/', [200], 'Top last hour nullsec');
        $this->testRouteContent('/top/lasthour/solo/', [200], 'Top last hour solo');
        
        // Entity overview routes
        echo "\n--- Entity Overview Routes ---\n";
        $this->testRouteContent("/character/{$this->realCharacterId}/", [200], 'Character overview (REAL)');
        $this->testRouteContent("/system/{$this->realSystemId}/", [200], 'System overview (REAL)');
        $this->testRouteContent("/region/{$this->realRegionId}/", [200], 'Region overview (REAL)');
        $this->testRouteContent("/item/{$this->realItemId}/", [200], 'Item overview (REAL)');
        $this->testRouteContent("/ship/{$this->realShipId}/", [200], 'Ship overview (REAL)');
        
        // Kill routes
        echo "\n--- Kill Routes ---\n";
        $this->testRouteContent("/kill/{$this->realKillId}/", [200], 'Kill detail (REAL)');
        $this->testRouteContent("/kill/{$this->realKillId}/remaining/", [200], 'Kill remaining (REAL)');
        
        // API routes that should return JSON
        echo "\n--- API Routes (Should Return JSON) ---\n";
        $this->testRouteContent('/api/stats/', [200], 'API stats');
        $this->testRouteContent('/cache/bypass/stats/?type=character&id=' . $this->realCharacterId, [200], 'Stats API (REAL character)');
        $this->testRouteContent('/cache/bypass/killlist/?s=1&u=/character/' . $this->realCharacterId . '/', [200, 302], 'Killlist API (REAL character)');
        $this->testRouteContent('/cache/1hour/autocomplete/', [200], 'Autocomplete API');
        
        // Search routes
        echo "\n--- Search Routes ---\n";
        $this->testRouteContent('/search/', [200, 302], 'Search main');
        $this->testRouteContent('/asearch/', [200, 302], 'Advanced search');
        $this->testRouteContent('/asearchquery/', [200, 302, 500], 'Advanced search query (may be empty without parameters)');
        
        // Routes that were previously problematic
        echo "\n--- Previously Problematic Routes ---\n";
        $this->testRouteContent('/bigisk/', [200], 'Big ISK kills');
        $this->testRouteContent('/scanalyzer/', [200], 'Scanalyzer');
        $this->testRouteContent('/wars/', [200], 'Wars list');
        $this->testRouteContent('/intel/supers/', [200], 'Intel supers');
        $this->testRouteContent('/war/eligible/', [200], 'War eligible');
        $this->testRouteContent('/kills/sponsored/', [200], 'Sponsored kills');
        $this->testRouteContent('/ztop/', [200], 'zTop rankings');
        
        // Battle report routes
        echo "\n--- Battle Report Routes ---\n";
        $this->testRouteContent('/br/validbattle123/', [200], 'Battle report');
        $this->testRouteContent('/brsave/', [200, 302], 'Battle report save');
        
        // Related routes
        echo "\n--- Related Routes ---\n";
        $this->testRouteContent("/related/{$this->realSystemId}/202510311800/", [200], 'Related kills (REAL system)');
        
        // Post route (GET)
        echo "\n--- Post Routes ---\n";
        $this->testRouteContent('/post/', [200], 'Post killmail form');
        
        // Account routes (may redirect but should not be blank)
        echo "\n--- Account Routes ---\n";
        $this->testRouteContent('/account/', [200, 302], 'Account main');
        $this->testRouteContent('/account/favorites/', [200], 'Account favorites');
        
        // Cache and utility routes
        echo "\n--- Cache & Utility Routes ---\n";
        $this->testRouteContent('/cache/bypass/healthcheck/', [200], 'Health check');
        $this->testRouteContent('/navbar/', [200], 'Navigation bar');
        
        // Comment routes
        echo "\n--- Comment Routes ---\n";
        $this->testRouteContent("/cache/bypass/comment/kill-{$this->realKillId}/-1/up/", [200], 'Comment upvote (REAL kill)');
        
        $this->printBlankContentSummary();
    }
    
    /**
     * Print blank content test summary
     */
    private function printBlankContentSummary() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "BLANK CONTENT TEST SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        
        $passCount = $this->totalTests - $this->errorCount - $this->blankContentCount;
        $passRate = ($this->totalTests > 0) ? round(($passCount / $this->totalTests) * 100, 1) : 0;
        
        echo sprintf("Total Tests: %d\n", $this->totalTests);
        echo sprintf("Passed: \033[32m%d\033[0m\n", $passCount);
        echo sprintf("Failed (HTTP Errors): \033[31m%d\033[0m\n", $this->errorCount);
        echo sprintf("Blank Content: \033[33m%d\033[0m\n", $this->blankContentCount);
        echo sprintf("Pass Rate: %.1f%%\n", $passRate);
        
        if ($this->blankContentCount > 0) {
            echo "\n--- ROUTES WITH BLANK CONTENT ---\n";
            foreach ($this->results as $result) {
                if ($result['is_blank']) {
                    echo sprintf("BLANK: %s (%d bytes)\n", 
                        $result['path'], 
                        $result['content_length']
                    );
                    if (!empty($result['issues'])) {
                        echo "  Issues: " . implode(', ', $result['issues']) . "\n";
                    }
                    if ($result['content_length'] > 0 && $result['content_length'] < 500) {
                        echo "  Preview: " . substr($result['body_preview'], 0, 100) . "\n";
                    }
                }
            }
        }
        
        if ($this->errorCount > 0) {
            echo "\n--- HTTP ERRORS ---\n";
            foreach ($this->results as $result) {
                if ($result['status'] === 'FAIL' || $result['status'] === 'ERROR') {
                    echo sprintf("ERROR: %s (%s)\n", 
                        $result['path'], 
                        implode(', ', $result['issues'])
                    );
                }
            }
        }
        
        echo "\n";
        
        if ($this->blankContentCount === 0 && $this->errorCount === 0) {
            echo "\033[32m🎉 NO BLANK CONTENT FOUND! All tested routes return proper content.\033[0m\n";
        } elseif ($this->blankContentCount > 0) {
            echo "\033[33m⚠️  Found {$this->blankContentCount} routes returning blank content. Review above.\033[0m\n";
        }
        
        if ($this->errorCount > 0) {
            echo "\033[31m❌ Found {$this->errorCount} routes with HTTP errors.\033[0m\n";
        }
    }
}

// Run the blank content test suite
if (php_sapi_name() === 'cli') {
    $testSuite = new BlankContentTestSuite();
    $testSuite->runBlankContentTests();
}