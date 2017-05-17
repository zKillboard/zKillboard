<?php

require_once __DIR__ . '/../../classes/Mdb.php';

use PHPUnit\Framework\TestCase;

final class Stats {
    public function getActivePvpStats($x) {
        return [];
    }
}

final class App {
    public function contentType($x) {
    }
}

final class ApiStatsTests extends TestCase {

    /**
     *  @runInSeparateProcess
     */
    public function testWillOutputObjectIfPvpStatsAreEmpty() {
        ob_start();
        $id = 1;
        $type = "thingo";
        global $mdb;
        $mdb = $this->createMock(Mdb::class);
        $app = new App();
        include './view/apistats.php';
        $output = ob_get_clean();
        $this->assertRegexp('/"activepvp":{}/', $output);
    }

}
