<?php

class SDE
{
    public static function manifest()
    {
        // The manifest pins every table to the same immutable release.
        $manifest = json_decode(self::request('https://sde.evetech.net/tranquility/latest/'), true, 512, JSON_THROW_ON_ERROR);
        if (empty($manifest['release']) || empty($manifest['tables'])) throw new RuntimeException('Invalid SDE manifest');
        return $manifest;
    }

    public static function rows($manifest, $table)
    {
        $url = $manifest['tables'][$table]['jsonl']['url'] ?? null;
        if (!$url) throw new RuntimeException("Missing SDE table $table");
        $file = tmpfile();
        if ($file === false) throw new RuntimeException('Unable to create SDE temporary file');
        try {
            self::request($url, $file);
            rewind($file);
            $count = 0;
            while (($line = fgets($file)) !== false) {
                if (trim($line) === '') continue;
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($row)) throw new RuntimeException("Invalid SDE row in $table");
                $count++;
                yield $row;
            }
            if (!feof($file) || $count === 0) throw new RuntimeException("Incomplete SDE table $table");
        } finally {
            fclose($file);
        }
    }

    private static function request($url, $file = null)
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 11, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'zkillboard.com', CURLOPT_ENCODING => '', CURLOPT_FOLLOWLOCATION => true, CURLOPT_RETURNTRANSFER => true]);
        if ($file !== null) curl_setopt($curl, CURLOPT_FILE, $file);
        $result = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        if ($result === false || $code !== 200) throw new RuntimeException("SDE request failed ($code): $url $error");
        return $result;
    }
}
