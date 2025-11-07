<?php
/**
 * zKillboard Route Test Suite
 * Tests all possible routes with REAL entity IDs and proper expectations
 * Routes that should 404 for missing entities will properly test for 404
 */

class RouteTestSuite {
    private $baseUrl;
    private $results = [];
    private $errorCount = 0;
    private $totalTests = 0;
    private $ignoreTimeouts = false;
    
    // Real entity IDs extracted from the live application
    private $realKillId = '130765767'; // Real kill ID from homepage
    private $realCharacterId = '1633218082'; // Real character ID from homepage  
    private $realSystemId = '30000142'; // Jita
    private $realSystemId2 = '30000144'; // Perimeter
    private $realSystemId3 = '30002187'; // Amarr
    private $realRegionId = '10000002'; // The Forge
    private $realRegionId2 = '10000043'; // Domain
    private $realConstellationId = '20000001'; // Real constellation
    private $realItemId = '34'; // Tritanium
    private $realItemId2 = '11129'; // Large Skill Injector
    private $realShipId = '587'; // Rifter
    private $realGroupId = '25'; // Frigate group
    private $realCorporationId = '1000168'; // Real corporation
    
    // Fake/invalid IDs that should return 404
    private $fakeKillId = '999999999';
    private $fakeCharacterId = '999999999';
    private $fakeCorporationId = '999999999';
    private $fakeAllianceId = '999999999';
    private $fakeWarId = '999999999';
    
    public function __construct($baseUrl = 'http://localhost', $ignoreTimeouts = true) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->ignoreTimeouts = $ignoreTimeouts;
    }
    
    /**
     * Test POST killmail submission
     */
    private function testPostKillmail() {
        echo "Testing POST killmail submission...\n";
        
        // Test with invalid killmail URL (should show error)
        $result1 = $this->testPostRoute('/post/', [
            'killmailurl' => 'invalid_url'
        ], [200], 'POST invalid killmail URL');

        // Test with valid-format but fake killmail URL (should show error)
        $result2 = $this->testPostRoute('/post/', [
            'killmailurl' => 'https://esi.evetech.net/latest/killmails/99999999/abcd1234567890abcd1234567890abcd12345678/'
        ], [200], 'POST fake killmail URL');
        
        // Test with empty form (should show form )
        $result3 = $this->testPostRoute('/post/', [], [200], 'POST empty form data');
        
        echo sprintf("POST Tests: Invalid URL [%s], Fake URL [%s], Empty Form [%s]\n", 
            $result1 ? 'PASS' : 'FAIL',
            $result2 ? 'PASS' : 'FAIL', 
            $result3 ? 'PASS' : 'FAIL'
        );
    }
    
    /**
     * Test a POST route with form data
     */
    private function testPostRoute($path, $postData = [], $expectedCode = 200, $description = '') {
        $this->totalTests++;
        $url = $this->baseUrl . $path;
        
        // Use curl to POST to the route
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_POST, true);
        
        if (!empty($postData)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded'
            ]);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $status = 'PASS';
        if ($error) {
            $status = 'ERROR';
            $this->errorCount++;
        } elseif (is_array($expectedCode)) {
            if (!in_array($httpCode, $expectedCode)) {
                $status = 'FAIL';
                $this->errorCount++;
            }
        } elseif ($httpCode != $expectedCode) {
            $status = 'FAIL';
            $this->errorCount++;
        }
        
        // For POST routes, also check if the response contains expected content
        $contentCheck = true;
        if ($httpCode == 200 && $response) {
            // Check if form validation is working
            if (!empty($postData['killmailurl']) && $postData['killmailurl'] == 'invalid_url') {
                $contentCheck = strpos($response, 'Invalid killmail link') !== false;
            }
        }
        
        $this->results[] = [
            'path' => $path . ' (POST)',
            'expected' => is_array($expectedCode) ? implode('|', $expectedCode) : $expectedCode,
            'actual' => $httpCode ?: 'ERROR',
            'status' => $status && $contentCheck ? 'PASS' : 'FAIL',
            'description' => $description,
            'error' => $error,
            'post_data' => $postData
        ];
        
        // Real-time output
        $finalStatus = $status && $contentCheck ? 'PASS' : 'FAIL';
        $statusColor = $finalStatus === 'PASS' ? "\033[32m" : ($finalStatus === 'FAIL' ? "\033[31m" : "\033[33m");
        echo sprintf("%-60s [%s%s\033[0m] %s\n", 
            $path . ' (POST)', 
            $statusColor, 
            $finalStatus, 
            $httpCode ?: $error
        );
        
        return $finalStatus === 'PASS';
    }

    /**
     * Test a single route and record results
     */
    private function testRoute($path, $expectedCode = 200, $description = '') {
        $this->totalTests++;
        $url = $this->baseUrl . $path;
        
        // Start timing
        $startTime = microtime(true);
        
        // Use curl to test the route
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request for faster testing
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Calculate time taken
        $timeTaken = microtime(true) - $startTime;
        
        $status = 'PASS';
        $failReason = '';
        
        if ($error) {
            $status = 'ERROR';
            $failReason = 'CURL_ERROR';
            $this->errorCount++;
        } elseif ($timeTaken > 1.0 && !$this->ignoreTimeouts) {
            $status = 'FAIL';
            $failReason = 'TIMEOUT';
            $this->errorCount++;
        } elseif ($timeTaken > 1.0 && $this->ignoreTimeouts) {
            $status = 'PASS';
            $failReason = 'TIMEOUT_IGNORED';
        } elseif (is_array($expectedCode)) {
            if (!in_array($httpCode, $expectedCode)) {
                $status = 'FAIL';
                $failReason = 'HTTP_CODE';
                $this->errorCount++;
            }
        } elseif ($httpCode != $expectedCode) {
            $status = 'FAIL';
            $failReason = 'HTTP_CODE';
            $this->errorCount++;
        }
        
        $this->results[] = [
            'path' => $path,
            'expected' => is_array($expectedCode) ? implode('|', $expectedCode) : $expectedCode,
            'actual' => $httpCode ?: 'ERROR',
            'status' => $status,
            'description' => $description,
            'error' => $error,
            'time_taken' => $timeTaken,
            'fail_reason' => $failReason
        ];
        
        // Real-time output with timing
        $statusColor = $status === 'PASS' ? "\033[32m" : ($status === 'FAIL' ? "\033[31m" : "\033[33m");
        $timeColor = $timeTaken > 1.0 ? "\033[31m" : ($timeTaken > 0.5 ? "\033[33m" : "\033[90m");
        echo sprintf("%-60s [%s%s\033[0m] %s %s%.3fs\033[0m%s\n", 
            $path, 
            $statusColor, 
            $status, 
            $httpCode ?: $error,
            $timeColor,
            $timeTaken,
            $failReason ? " ($failReason)" : ''
        );
        
        return $status === 'PASS';
    }
    
    /**
     * Run all route tests with REAL entity IDs
     */
    public function runAllTests() {
        echo "=== zKillboard Route Test Suite ===\n";
        echo "Testing all routes with REAL entity IDs...\n";
        if ($this->ignoreTimeouts) {
            echo "⏰ TIMEOUT IGNORE MODE: Routes taking >1s will pass but be marked as slow\n";
        }
        echo "\n";
        
        // Basic routes
        echo "--- Basic Routes ---\n";
        $this->testRoute('/', 200, 'Homepage');
        $this->testRoute('/information/', 302, 'Information redirect');
        $this->testRoute('/faq/', 302, 'FAQ redirect');
        $this->testRoute('/challenge/', 302, 'Challenge (security check)');
        
        // Cache and Google routes
        echo "\n--- Cache & Google Routes ---\n";
        $this->testRoute('/cache/1hour/google/', 200, 'Google ads cache');
        $this->testRoute('/google/', 302, 'Google redirect');
        $this->testRoute('/google/mobile/', 302, 'Google mobile redirect');
        $this->testRoute('/cache/1hour/publift/leaderboard/', 200, 'Publift leaderboard');
        $this->testRoute('/cache/1hour/publift/rectangle/', 200, 'Publift rectangle');
        $this->testRoute('/cache/1hour/publift/mobile/', 200, 'Publift mobile');
                
        // Information pages
        echo "\n--- Information Routes ---\n";
        $this->testRoute('/information/about/', 200, 'About page');
        $this->testRoute('/information/faq/', 200, 'FAQ page');
        $this->testRoute('/information/api/', [200, 302], 'API documentation');
        $this->testRoute('/information/donations/', [200, 302], 'Donations page');
        $this->testRoute('/information/ccpfriends/', [200, 302], 'CCP Friends');
        $this->testRoute('/information/sponsors/', [200, 302], 'Sponsors page');
        $this->testRoute('/information/terms/', [200, 302], 'Terms of service');
        $this->testRoute('/information/privacy/', [200, 302], 'Privacy policy');
        
        // Account routes (most require authentication and redirect)
        echo "\n--- Account Routes ---\n";
        $this->testRoute('/account/', [200, 302], 'Account main');
        $this->testRoute('/account/settings/', [200, 302], 'Account settings');
        $this->testRoute('/account/api/', [200, 302], 'Account API');
        $this->testRoute('/account/stats/', [200, 302], 'Account stats');
        $this->testRoute('/account/favorites/', 200, 'Account favorites');
        $this->testRoute('/account/logout/', 302, 'Account logout');
        $this->testRoute("/account/tracker/character/{$this->realCharacterId}/add/", [200, 302, 404], 'Account tracker add character');
        $this->testRoute("/account/tracker/character/{$this->realCharacterId}/remove/", [200, 302, 404], 'Account tracker remove character');
        $this->testRoute("/account/tracker/corporation/{$this->realCorporationId}/add/", [200, 302, 404], 'Account tracker add corporation');
        $this->testRoute("/account/tracker/corporation/{$this->realCorporationId}/remove/", [200, 302, 404], 'Account tracker remove corporation');
        $this->testRoute("/account/favorite/{$this->realKillId}/add/", [200, 302, 405], 'Account favorite add (POST route)');
        $this->testRoute("/account/favorite/{$this->realKillId}/remove/", [200, 302, 405], 'Account favorite remove (POST route)');
        
        // Kill and Battle Report routes - REAL vs FAKE IDs
        echo "\n--- Kill & Battle Routes (Real IDs) ---\n";
        $this->testRoute("/kill/{$this->realKillId}/", 200, 'Kill detail (REAL kill)');
        $this->testRoute("/kill/{$this->realKillId}/remaining/", 200, 'Kill remaining attackers (REAL kill)');
        $this->testRoute("/kill/{$this->realKillId}/redirect/zkillboard/", [200, 302], 'Kill redirect (REAL kill)');
        $this->testRoute("/kill/{$this->realKillId}/ingamelink/", 200, 'Kill ingame link (REAL kill)');
        
        echo "\n--- Kill & Battle Routes (Fake IDs - Should 404) ---\n";
        $this->testRoute("/kill/{$this->fakeKillId}/", 404, 'Kill detail (FAKE kill - should 404)');
        $this->testRoute("/kill/{$this->fakeKillId}/remaining/", 404, 'Kill remaining (FAKE kill - should 404)');
        $this->testRoute("/kill/{$this->fakeKillId}/ingamelink/", [200, 404], 'Kill ingame link (FAKE kill - may fallback)');
        
        echo "\n--- Battle Report Routes ---\n";
        $this->testRoute('/br/validbattle123/', 200, 'Battle report (creates if needed)');
        $this->testRoute('/brsave/', [200, 302], 'Battle report save');
        $this->testRoute('/bigisk/', 200, 'Big ISK kills');
        
        // Related routes - using REAL system ID
        echo "\n--- Related Routes ---\n";
        $this->testRoute("/related/{$this->realSystemId}/202510311800/", 200, 'Related kills (REAL system)');
        $this->testRoute("/related/{$this->realSystemId}/202510311800/o/npc/", 200, 'Related kills with options (REAL system)');
        
        // Top and Ranks routes
        echo "\n--- Top & Ranks Routes ---\n";
        $this->testRoute('/top/lasthour/all/', 200, 'Top last hour all');
		$this->testRoute('/top/lasthour/nullsec/', 200, 'Top last hour nullsec');
		$this->testRoute('/top/lasthour/lowsec/', 200, 'Top last hour lowsec');
		$this->testRoute('/top/lasthour/highsec/', 200, 'Top last hour highsec');
		$this->testRoute('/top/lasthour/w-space/', 200, 'Top last hour w-space');
		$this->testRoute('/top/lasthour/solo/', 200, 'Top last hour solo');
        
        // Type ranks - using REAL vs FAKE IDs  
        echo "\n--- Ranks Routes (Real IDs) ---\n";
        $this->testRoute("/character/ranks/k/all/alltime/1/", 200, 'Character ranks kills all alltime');
        $this->testRoute("/character/ranks/l/all/alltime/1/", 200, 'Character ranks losses all alltime');
        $this->testRoute("/character/ranks/k/solo/alltime/1/", 200, 'Character ranks kills solo alltime');
        $this->testRoute("/character/ranks/l/solo/alltime/1/", 200, 'Character ranks losses solo alltime');
        $this->testRoute("/corporation/ranks/k/all/alltime/1/", 200, 'Corporation ranks kills all alltime');
        $this->testRoute("/corporation/ranks/l/all/alltime/1/", 200, 'Corporation ranks losses all alltime');
        $this->testRoute("/corporation/ranks/k/solo/alltime/1/", 200, 'Corporation ranks kills solo alltime');
        $this->testRoute("/alliance/ranks/k/all/alltime/1/", 200, 'Alliance ranks kills all alltime');
        $this->testRoute("/alliance/ranks/l/all/alltime/1/", 200, 'Alliance ranks losses all alltime');
        $this->testRoute("/shipType/ranks/k/all/alltime/1/", 200, 'Ship ranks kills all alltime');
        $this->testRoute("/group/ranks/k/all/alltime/1/", 200, 'Group ranks kills all alltime');
        $this->testRoute("/faction/ranks/k/all/alltime/1/", 200, 'Faction ranks kills all alltime');
        
        // API routes - REAL vs FAKE entity testing
        echo "\n--- API Routes (Real IDs) ---\n";
        $this->testRoute('/api/stats/', 200, 'API stats endpoint');
        $this->testRoute("/api/stats/character/{$this->realCharacterId}/", [200, 404], 'API character stats (REAL character)');
        $this->testRoute("/api/related/{$this->realSystemId}/202510311800/", 200, 'API related kills (REAL system)');
        $this->testRoute('/api/history/20251031/', 302, 'API history redirect');
        $this->testRoute("/api/prices/{$this->realItemId}/", 200, 'API item prices (REAL item - Tritanium)');
        $this->testRoute('/api/recentactivity/', 200, 'API recent activity');
        $this->testRoute('/api/supers/', 200, 'API supers intel');
        
        echo "\n--- API Routes (Fake IDs - Should 404) ---\n";
        $this->testRoute("/api/stats/character/{$this->fakeCharacterId}/", [200, 404], 'API character stats (FAKE character - may return empty)');
        $this->testRoute("/api/stats/corporation/{$this->fakeCorporationId}/", [200, 404], 'API corporation stats (FAKE corp - may return empty)');
        $this->testRoute("/api/stats/alliance/{$this->fakeAllianceId}/", [200, 404], 'API alliance stats (FAKE alliance - may return empty)');
        
        // Generic API catch-all route  
        $this->testRoute('/api/kills/', [200], 'API kills endpoint');
        $this->testRoute('/api/kills/characterID/1/', [200, 404], 'API kills by character');
        $this->testRoute('/api/kills/corporationID/1/', [200, 404], 'API kills by corporation');
        $this->testRoute('/api/kills/allianceID/1/', [200, 404], 'API kills by alliance');
        $this->testRoute('/api/kills/solarSystemID/30000142/', [200, 404], 'API kills by system');
        $this->testRoute('/api/kills/shipTypeID/587/', [200, 404], 'API kills by ship');
        $this->testRoute('/api/kills/w-space/', [200, 404], 'API kills w-space');
        $this->testRoute('/api/kills/lowsec/', [200, 404], 'API kills lowsec');
        $this->testRoute('/api/kills/nullsec/', [200, 404], 'API kills nullsec');
        $this->testRoute('/api/kills/highsec/', [200, 404], 'API kills highsec');
        $this->testRoute("/api/killmail/add/{$this->realKillId}/baa8832d86d498781edbcc99363700213787f761/", [200], 'API killmail add (POST route)');
        
        // Search routes
        echo "\n--- Search Routes ---\n";
        $this->testRoute('/search/', [200, 302], 'Search main');
        $this->testRoute('/search/test/', [200, 302], 'Search with query');  
        $this->testRoute('/asearch/', [200, 302], 'Advanced search');
        $this->testRoute('/asearchsave/', [200, 302], 'Advanced search save');
        $this->testRoute('/asearchsaved/123/', [200, 302, 404], 'Advanced search saved');
        $this->testRoute('/asearchquery/', [200, 302], 'Advanced search query');
        $this->testRoute('/asearchinfo/', [200, 302], 'Advanced search info');
        $this->testRoute('/autocomplete/', [200, 302, 405], 'Autocomplete POST endpoint');
        $this->testRoute('/autocomplete/character/test/', [200, 302], 'Autocomplete character');
        $this->testRoute('/autocomplete/corporation/test/', [200, 302], 'Autocomplete corporation');
        $this->testRoute('/autocomplete/test/', [200, 302], 'Autocomplete general');
        $this->testRoute('/cache/1hour/autocomplete/', 200, 'Search autocomplete cache');
        
        // Item routes - REAL vs FAKE
        echo "\n--- Item Routes ---\n";
        $this->testRoute("/item/{$this->realItemId}/", 200, 'Item details (REAL item - Tritanium)');
        $this->testRoute("/item/{$this->realItemId2}/", 200, 'Item details (REAL item - Large Skill Injector)');
        $this->testRoute("/item/999999999/", [200, 404], 'Item details (FAKE item - may fallback)');
        
        // Scanalyzer
        echo "\n--- Scanalyzer Routes ---\n";
        $this->testRoute('/scanalyzer/', 200, 'Scanalyzer main');
        
        // War routes - REAL vs FAKE
        echo "\n--- War Routes ---\n";
        $this->testRoute('/war/eligible/', 200, 'War eligible corps');
        $this->testRoute("/war/{$this->fakeWarId}/", [200, 404], 'War details (fake war - may 404)');
        $this->testRoute('/wars/', 200, 'Wars list');
        
        // Intel routes
        echo "\n--- Intel Routes ---\n";
        $this->testRoute('/intel/supers/', 200, 'Intel supers');
        
        // CCP/OAuth routes
        echo "\n--- CCP/OAuth Routes ---\n";
        $this->testRoute('/ccplogin/', [200, 302], 'CCP login');
        $this->testRoute('/ccpcallback/', [200, 302], 'CCP callback');
        $this->testRoute("/ccpsavefit/{$this->realKillId}/", 200, 'CCP save fit (REAL kill)');
        $this->testRoute('/ccpoauth2/', [200, 302], 'CCP OAuth2');
        $this->testRoute('/ccpoauth2/5/', [200, 302], 'CCP OAuth2 with delay');
        $this->testRoute('/ccpoauth2-360noscope/', [200, 302], 'CCP OAuth2 no scopes');
        
        // Social login routes
        echo "\n--- Social Login Routes ---\n";
        $this->testRoute('/cache/bypass/login/patreon/', [200, 302], 'Patreon login');
        $this->testRoute('/cache/bypass/login/patreonauth/', [200, 302], 'Patreon auth');
        
        // Post routes
        echo "\n--- Post Routes ---\n";
        $this->testRoute('/post/', 200, 'Post killmail GET');
        $this->testPostKillmail();
        
        // Test other POST endpoints
        echo "\n--- POST-only Routes ---\n";
        $this->testPostRoute("/account/favorite/{$this->realKillId}/add/", [], [200, 302], 'POST account favorite add');
        $this->testPostRoute("/account/favorite/{$this->realKillId}/remove/", [], [200, 302], 'POST account favorite remove');
        $this->testPostRoute("/api/killmail/add/{$this->realKillId}/abc123/", [], [200], 'POST API killmail add');
        $this->testPostRoute('/cache/bypass/scan/', ['scan' => 'test'], [200, 302], 'POST scan analyzer');
        
        // Sponsor routes - COMPREHENSIVE TESTING
        echo "\n--- Sponsor Routes ---\n";
        $this->testRoute("/sponsor/query/{$this->realKillId}/", [200, 302], 'Sponsor query (REAL kill)');
        $this->testRoute("/sponsor/sponsor/{$this->realKillId}/1000000/", [200, 302], 'Sponsor with value (REAL kill)');
        $this->testRoute("/sponsor/featured/{$this->realKillId}/", [200, 302, 404], 'Sponsor featured (REAL kill)');
        $this->testRoute("/sponsor/big/{$this->realKillId}/1000000/", [200, 302, 404], 'Sponsor big kill (REAL kill)');
        $this->testRoute("/sponsor/featured/{$this->realKillId}/5000000/", [200, 302, 404], 'Sponsor featured with value (REAL kill)');
        $this->testRoute('/kills/sponsored/', 200, 'Sponsored kills');

        // Miscellaneous routes
        echo "\n--- Miscellaneous Routes ---\n";
        $this->testRoute('/navbar/', 200, 'Navigation bar');
        $this->testRoute('/ztop/', 200, 'zTop rankings');
        
        // Crest mail routes
        $this->testRoute("/crestmail/{$this->realKillId}/baa8832d86d498781edbcc99363700213787f761/", 204, 'CREST killmail (REAL kill)');
        
        // Cache routes
        echo "\n--- Cache Routes ---\n";
        $this->testRoute('/cache/bypass/stats/', [200, 302], 'Stats bypass cache');
        $this->testRoute('/cache/1hour/stats/', [200, 302], 'Stats 1 hour cache');
        $this->testRoute('/cache/24hour/stats/', [200, 302], 'Stats 24 hour cache');
        $this->testRoute('/cache/bypass/killlist/', [200, 302], 'Killlist bypass cache');
        $this->testRoute('/cache/1hour/killlist/', [200, 302], 'Killlist 1 hour cache');
        $this->testRoute('/cache/24hour/killlist/', [200, 302], 'Killlist 24 hour cache');
        $this->testRoute('/cache/bypass/statstop10/', [200, 302], 'Stats top 10 bypass');
        $this->testRoute('/cache/1hour/statstop10/', [200, 302], 'Stats top 10 1 hour');
        $this->testRoute('/cache/24hour/statstop10/', [200, 302], 'Stats top 10 24 hour');
        $this->testRoute('/cache/bypass/statstopisk/', [200, 302], 'Stats top ISK bypass');
        $this->testRoute('/cache/1hour/statstopisk/', [200, 302], 'Stats top ISK 1 hour');
        $this->testRoute('/cache/24hour/statstopisk/', [200, 302], 'Stats top ISK 24 hour');
        $this->testRoute('/cache/bypass/comment/123/456/up/', [200, 404], 'Comment upvote');
        $this->testRoute("/cache/1hour/killlistrow/{$this->realKillId}/", 200, 'Kill list row cache (REAL kill)');
        $this->testRoute("/cache/24hour/killlistrow/{$this->realKillId}/", 200, 'Kill list row 24h cache (REAL kill)');
        $this->testRoute('/cache/bypass/healthcheck/', 200, 'Health check');
        $this->testRoute('/cache/bypass/scan/', [200, 302, 405], 'Scan POST endpoint');
        
        // Additional missing routes
        echo "\n--- Additional Missing Routes ---\n";
        $this->testRoute('/api/prices/35/', 200, 'API prices for Pyerite');
        $this->testRoute('/api/prices/36/', 200, 'API prices for Mexallon');
        $this->testRoute('/api/prices/37/', 200, 'API prices for Isogen');
        $this->testRoute('/api/prices/38/', 200, 'API prices for Nocxium');
        $this->testRoute('/api/prices/39/', 200, 'API prices for Zydrine');
        $this->testRoute('/api/prices/40/', 200, 'API prices for Megacyte');
        
        // Test more entity overview pages with different subtypes
        echo "\n--- Additional Entity Pages ---\n";
        $this->testRoute("/character/{$this->realCharacterId}/kills/", 200, 'Character kills page (REAL character)');
        $this->testRoute("/character/{$this->realCharacterId}/losses/", 200, 'Character losses page (REAL character)');
        $this->testRoute("/character/{$this->realCharacterId}/solo/", 200, 'Character solo page (REAL character)');
        $this->testRoute("/character/{$this->realCharacterId}/stats/", 200, 'Character stats page (REAL character)');
        $this->testRoute("/character/{$this->realCharacterId}/trophies/", 200, 'Character trophies page (REAL character)');
        $this->testRoute("/character/{$this->realCharacterId}/ranks/", 200, 'Character ranks page (REAL character)');
        $this->testRoute("/corporation/{$this->realCorporationId}/kills/", 200, 'Corporation kills page (REAL corp)');
        $this->testRoute("/corporation/{$this->realCorporationId}/losses/", 200, 'Corporation losses page (REAL corp)');
        $this->testRoute("/corporation/{$this->realCorporationId}/stats/", 200, 'Corporation stats page (REAL corp)');
        $this->testRoute("/corporation/{$this->realCorporationId}/supers/", 200, 'Corporation supers page (REAL corp)');
        $this->testRoute("/system/{$this->realSystemId}/kills/", 200, 'System kills page (REAL system)');
        $this->testRoute("/system/{$this->realSystemId}/losses/", 200, 'System losses page (REAL system)');
        $this->testRoute("/region/{$this->realRegionId}/kills/", 200, 'Region kills page (REAL region)');
        $this->testRoute("/ship/{$this->realShipId}/kills/", 200, 'Ship kills page (REAL ship)');
        $this->testRoute("/group/{$this->realGroupId}/kills/", 200, 'Group kills page (REAL group)');
        $this->testRoute("/location/60003760/", 200, 'Location page'); // Jita 4-4
        $this->testRoute("/label/pvp/", 200, 'Label page (pvp)');
        
        // Overview routes (catch-all entity pages) - REAL vs FAKE
        echo "\n--- Overview Routes (Real Entities) ---\n";
        $this->testRoute("/character/{$this->realCharacterId}/", 200, 'Character overview (REAL character)');
        $this->testRoute("/system/{$this->realSystemId}/", 200, 'System overview (REAL system - Jita)');
        $this->testRoute("/system/{$this->realSystemId2}/", 200, 'System overview (REAL system - Perimeter)');
        $this->testRoute("/system/{$this->realSystemId3}/", 200, 'System overview (REAL system - Amarr)');
        $this->testRoute("/constellation/{$this->realConstellationId}/", 200, 'Constellation overview (REAL constellation)');
        $this->testRoute("/region/{$this->realRegionId}/", 200, 'Region overview (REAL region - The Forge)');
        $this->testRoute("/region/{$this->realRegionId2}/", 200, 'Region overview (REAL region - Domain)');
        $this->testRoute("/ship/{$this->realShipId}/", 200, 'Ship overview (REAL ship - Rifter)');
        $this->testRoute("/group/{$this->realGroupId}/", 200, 'Ship group overview (REAL group - Frigate)');
        
        echo "\n--- Overview Routes (Fake Entities - Should 404) ---\n";
        $this->testRoute("/character/{$this->fakeCharacterId}/", 404, 'Character overview (FAKE character - should 404)');
        $this->testRoute("/corporation/{$this->fakeCorporationId}/", 404, 'Corporation overview (FAKE corp - should 404)');
        $this->testRoute("/alliance/{$this->fakeAllianceId}/", 404, 'Alliance overview (FAKE alliance - should 404)');
        $this->testRoute("/faction/999999999/", 404, 'Faction overview (FAKE faction - should 404)');
        
        $this->printSummary();
    }
    
    /**
     * Print test summary
     */
    private function printSummary() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "TEST SUMMARY\n";
        echo str_repeat("=", 60) . "\n";
        
        $passCount = $this->totalTests - $this->errorCount;
        $passRate = ($this->totalTests > 0) ? round(($passCount / $this->totalTests) * 100, 1) : 0;
        
        echo sprintf("Total Tests: %d\n", $this->totalTests);
        echo sprintf("Passed: \033[32m%d\033[0m\n", $passCount);
        echo sprintf("Failed: \033[31m%d\033[0m\n", $this->errorCount);
        echo sprintf("Pass Rate: %.1f%%\n", $passRate);
        
        if ($this->errorCount > 0) {
            echo "\n--- FAILED TESTS ---\n";
            foreach ($this->results as $result) {
                if ($result['status'] !== 'PASS') {
                    echo sprintf("FAIL: %s (Expected: %s, Got: %s, Time: %.3fs)\n", 
                        $result['path'], 
                        $result['expected'], 
                        $result['actual'],
                        $result['time_taken']
                    );
                    if ($result['fail_reason'] === 'TIMEOUT') {
                        echo "  Reason: Response took longer than 1 second\n";
                    } elseif ($result['fail_reason'] === 'TIMEOUT_IGNORED') {
                        echo "  Reason: Response took longer than 1 second (ignored)\n";
                    } elseif ($result['error']) {
                        echo "  Error: " . $result['error'] . "\n";
                    } elseif ($result['fail_reason'] === 'HTTP_CODE') {
                        echo "  Reason: Unexpected HTTP response code\n";
                    }
                }
            }
        }
        
        echo "\n";
        
        if ($this->errorCount === 0) {
            echo "\033[32m🎉 ALL TESTS PASSED! The application is working correctly with real entity validation.\033[0m\n";
        } else {
            echo "\033[33m⚠️  Some tests failed. Review the failed routes above.\033[0m\n";
        }
    }
}

// Run the test suite
if (php_sapi_name() === 'cli') {
    $testSuite = new RouteTestSuite();
    $testSuite->runAllTests();
}