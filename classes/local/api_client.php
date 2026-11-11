<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_aicoursebuilder\local;

/**
 * LMS Labs API client used for course planning.
 * @package    local_aicoursebuilder
 * @copyright  2026 LMS-Labs
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class api_client {
    /**
     * Get configured LMS Labs credentials without exposing them to the browser.
     *
     * @return array
     */
    private static function credentials(): array {
        global $CFG, $DB;
        $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
        if (is_readable($aiconfiglib)) {
            require_once($aiconfiglib);
        }
        $siteid = '';
        $apikey = '';
        if (function_exists('local_aiconfig_get_siteid')) {
            $siteid = trim((string)local_aiconfig_get_siteid());
        }
        if (function_exists('local_aiconfig_get_apikey')) {
            $apikey = trim((string)local_aiconfig_get_apikey());
        }
        if ($siteid === '') {
            $siteid = trim((string)get_config('local_aicoursebuilder', 'siteid'));
        }
        if ($apikey === '') {
            $apikey = trim((string)get_config('local_aicoursebuilder', 'apikey'));
        }
        if ($siteid === '' || $apikey === '') {
            throw new \moodle_exception('missingcredentials', 'local_aicoursebuilder');
        }
        return [$siteid, $apikey];
    }

    /**
     * Call an LMS Labs JSON route from a native plugin adapter.
     *
     * Credentials are injected server-side and never returned in errors.
     *
     * @param string $method GET or POST
     * @param string $path Absolute path beginning with /
     * @param array|null $payload
     * @param int $timeout
     * @param string[] $headers Additional validated HTTP headers.
     * @return array
     */
    public static function request_json(
        string $method,
        string $path,
        ?array $payload = null,
        int $timeout = 120,
        array $headers = []
    ): array {
        global $CFG, $DB;
        [$siteid, $apikey] = self::credentials();
        if (!in_array($method, ['GET', 'POST'], true) || $path === '' || $path[0] !== '/') {
            throw new \moodle_exception('invalidapiurl', 'local_aicoursebuilder');
        }
        if ($method === 'GET' && $headers) {
            throw new \invalid_parameter_exception('Custom headers are only supported for POST requests.');
        }
        foreach ($headers as $header) {
            if (!is_string($header) || !preg_match('/^[A-Za-z0-9-]+:[ \t]*[^\r\n]*$/D', $header)) {
                throw new \invalid_parameter_exception('Invalid outbound header.');
            }
        }
        $baseurl = trim((string)get_config('local_aicoursebuilder', 'apiurl'));
        if ($baseurl === '') {
            try {
                if ($DB->get_manager()->table_exists('local_aiconfig')) {
                    $config = $DB->get_record('local_aiconfig', ['id' => 1], 'apiurl');
                    $baseurl = trim((string)($config->apiurl ?? ''));
                }
            } catch (\Throwable $e) {
                // The central config plugin is optional; use the documented default below.
                $baseurl = '';
            }
        }
        if ($baseurl === '') {
            $baseurl = 'https://lms-labs.com';
        }
        if (!preg_match('#^https://#i', $baseurl) && !preg_match('#^http://localhost(?::\d+)?$#i', $baseurl)) {
            throw new \moodle_exception('invalidapiurl', 'local_aicoursebuilder');
        }

        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => max(5, min(180, $timeout)),
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_FOLLOWLOCATION' => false,
        ]);
        $url = rtrim($baseurl, '/') . $path;
        if ($method === 'GET') {
            // Job polling uses an unguessable job ID and, like existing Moodle plugin
            // pollers, must not leak credentials into URL logs, proxies, or browser history.
            if (!empty($payload)) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($payload);
            }
            $raw = $curl->get($url);
        } else {
            $curl->setHeader(array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers));
            $body = ($payload ?? []) + ['siteId' => $siteid, 'apiKey' => $apikey];
            $raw = $curl->post($url, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        $httpstatus = (int)($curl->get_info()['http_code'] ?? 0);
        $decoded = json_decode((string)$raw, true);
        if ($curl->get_errno() || $httpstatus < 200 || $httpstatus >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? (string)($decoded['error'] ?? $decoded['message'] ?? '') : '';
            $message = clean_param($message, PARAM_TEXT);
            throw new \moodle_exception(
                'generationfailed',
                'local_aicoursebuilder',
                '',
                '',
                $message !== '' ? $message : 'The LMS Labs route returned an invalid response (HTTP ' . $httpstatus . ').'
            );
        }
        return $decoded;
    }

    /**
     * Submit a curriculum planning request. Planning does not deduct credits.
     *
     * @param array $payload
     * @return array
     */
    public static function plan(array $payload): array {
        [$siteid, $apikey] = self::credentials();
        $baseurl = trim((string)get_config('local_aicoursebuilder', 'apiurl'));
        if ($baseurl === '') {
            $baseurl = 'https://lms-labs.com';
        }
        $baseurl = rtrim($baseurl, '/');
        if (!preg_match('#^https://#i', $baseurl) && !preg_match('#^http://localhost(?::\d+)?$#i', $baseurl)) {
            throw new \moodle_exception('invalidapiurl', 'local_aicoursebuilder');
        }
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json', 'Accept: application/json']);
        $response = $curl->post(
            $baseurl . '/api/moodle/ai-course-builder/plan',
            json_encode($payload + ['siteId' => $siteid, 'apiKey' => $apikey], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        $status = (int)($curl->get_info()['http_code'] ?? 0);
        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['success']) || !is_array($decoded['plan'] ?? null)) {
            $message = is_array($decoded) ? (string)($decoded['error'] ?? '') : '';
            throw new \moodle_exception('planningfailed', 'local_aicoursebuilder', '', '', $message ?: 'The planning service returned an invalid response.');
        }
        return $decoded['plan'];
    }
}
