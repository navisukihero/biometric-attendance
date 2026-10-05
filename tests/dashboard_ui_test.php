<?php

declare(strict_types=1);

/**
 * Non-mutating regression checks for the administrator dashboard redesign.
 *
 * This test intentionally reads source files instead of connecting to MySQL.
 * It protects the dashboard's navigation, metric wording, accessibility, and
 * responsive CSS without creating records or depending on sample data.
 */

function dashboard_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function dashboard_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' (expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true) . ')'
        );
    }
}

function dashboard_source(string $path, string $label): string
{
    $source = file_get_contents($path);
    dashboard_assert(is_string($source), $label . ' source must be readable');
    return $source;
}

/** Return one media-query section, ending immediately before the next one. */
function dashboard_media_section(string $css, int $width): string
{
    $matched = preg_match(
        '/@media\s*\(max-width\s*:\s*' . $width . 'px\s*\)/i',
        $css,
        $match,
        PREG_OFFSET_CAPTURE
    );
    if ($matched !== 1) {
        return '';
    }

    $start = (int) $match[0][1];
    $next = strpos($css, '@media', $start + strlen((string) $match[0][0]));
    return substr($css, $start, $next === false ? null : $next - $start);
}

$root = realpath(__DIR__ . '/..');
dashboard_assert(is_string($root), 'Project root must resolve');

try {
    $dashboard = dashboard_source($root . '/pages/dashboard.php', 'Dashboard page');
    $functions = dashboard_source($root . '/includes/functions.php', 'Shared functions');
    $app = dashboard_source($root . '/app.php', 'Administrator router');
    $layout = dashboard_source($root . '/includes/layout.php', 'Administrator layout');
    $css = dashboard_source($root . '/assets/css/app.css', 'Application stylesheet');
    $normalizedDashboard = preg_replace('/\s+/', ' ', $dashboard) ?? $dashboard;
    $normalizedLayout = preg_replace('/\s+/', ' ', $layout) ?? $layout;

    dashboard_assert(
        preg_match('/<section\b[^>]*class=["\'][^"\']*\badmin-dashboard\b[^"\']*["\']/i', $dashboard) === 1,
        'Dashboard must retain the scoped admin-dashboard root'
    );

    // All established modules must remain one click away after the visual
    // redesign. These are exact, local routes; no replacement placeholders or
    // JavaScript navigation are accepted.
    $appRoutes = [
        'employees',
        'employee_new',
        'attendance',
        'schedule',
        'leave',
        'overtime',
        'holidays',
        'payroll',
        'payroll_settings',
        'payslips',
        'reports',
        'audit_logs',
        'settings',
    ];
    foreach ($appRoutes as $route) {
        dashboard_assert(
            preg_match(
                '/href\s*=\s*["\']app\.php\?page=' . preg_quote($route, '/') . '["\']/i',
                $dashboard
            ) === 1,
            'Dashboard must preserve the app.php?page=' . $route . ' route'
        );
    }

    // A visible dashboard link is useful only when the central router accepts
    // the same page and its page file exists. Keep this as a subset assertion
    // so future, intentionally added administrator pages do not make the test
    // brittle.
    preg_match('/\$allowed\s*=\s*\[([^\]]*)\]/s', $app, $adminAllowlistMatch);
    dashboard_assert(isset($adminAllowlistMatch[1]), 'Administrator router must define an explicit page allowlist');
    preg_match_all('/[\'\"]([a-z_-]+)[\'\"]/', (string) $adminAllowlistMatch[1], $adminPageMatches);
    $allowedAdminPages = $adminPageMatches[1] ?? [];
    foreach ($appRoutes as $route) {
        dashboard_assert(
            in_array($route, $allowedAdminPages, true),
            'Administrator router must allow the linked page: ' . $route
        );
        dashboard_assert(
            is_file($root . '/pages/' . $route . '.php'),
            'Linked administrator page file must exist: pages/' . $route . '.php'
        );
    }
    dashboard_assert(
        str_contains($app, "require __DIR__ . '/pages/' . \$page . '.php';"),
        'Administrator router must load pages only after allowlist validation'
    );
    dashboard_assert(
        preg_match('/href\s*=\s*["\']biometric\.php["\']/i', $dashboard) === 1,
        'Dashboard must preserve the biometric.php terminal route'
    );

    preg_match_all(
        '/data-dashboard-module\s*=\s*["\']([a-z_-]+)["\']/i',
        $dashboard,
        $moduleMatches
    );
    $actualModules = $moduleMatches[1] ?? [];
    sort($actualModules);
    $expectedModules = [
        'attendance',
        'audit-logs',
        'employees',
        'holidays',
        'leave',
        'overtime',
        'payroll',
        'payroll-settings',
        'payslips',
        'reports',
        'schedule',
        'settings',
        'terminal',
    ];
    sort($expectedModules);
    dashboard_assert_same(
        $expectedModules,
        $actualModules,
        'Dashboard must expose every connected attendance/payroll module card'
    );

    // A dashboard is an overview, not a mutation endpoint. Keeping it free of
    // forms and timers also prevents accidental submissions and the historical
    // auto-refresh/reload behavior from returning.
    $forbiddenDashboardFragments = [
        '<form' => 'Dashboard must not contain an action form',
        '$_POST' => 'Dashboard must not process POST input',
        'method="post"' => 'Dashboard must not submit POST requests',
        "method='post'" => 'Dashboard must not submit POST requests',
        '<script' => 'Dashboard must not add page-local JavaScript',
        'setInterval(' => 'Dashboard must not start a refresh interval',
        'setTimeout(' => 'Dashboard must not start a refresh timer',
        'location.reload' => 'Dashboard must not reload itself',
        'window.location' => 'Dashboard must use ordinary preserved links',
        'http-equiv="refresh"' => 'Dashboard must not use meta refresh',
    ];
    foreach ($forbiddenDashboardFragments as $fragment => $message) {
        dashboard_assert(
            !str_contains(strtolower($dashboard), strtolower($fragment)),
            $message
        );
    }
    dashboard_assert(
        !str_contains($dashboard, 'HR Review Queue')
            && !str_contains($dashboard, 'admin-dashboard__operations-card'),
        'Dashboard must not restore the redundant HR Review Queue section'
    );

    // One attendance row can represent two physical events. The metric must
    // count non-null Time In and Time Out values, not merely COUNT(*) rows.
    $countsBothColumns = preg_match(
        '/COUNT\s*\(\s*(?:a\.)?time_in\s*\)\s*\+\s*COUNT\s*\(\s*(?:a\.)?time_out\s*\)/i',
        $normalizedDashboard
    ) === 1;
    $sumsBothColumns = preg_match(
        '/SUM\s*\([^;]*?(?:a\.)?time_in\s+IS\s+NOT\s+NULL[^;]*?\+[^;]*?(?:a\.)?time_out\s+IS\s+NOT\s+NULL[^;]*?\)/i',
        $normalizedDashboard
    ) === 1 || (
        preg_match('/SUM\s*\([^;]*?(?:a\.)?time_in\s+IS\s+NOT\s+NULL[^;]*?\)/i', $normalizedDashboard) === 1
        && preg_match('/SUM\s*\([^;]*?(?:a\.)?time_out\s+IS\s+NOT\s+NULL[^;]*?\)/i', $normalizedDashboard) === 1
    );
    dashboard_assert(
        $countsBothColumns || $sumsBothColumns,
        'Scans-today SQL must count both non-null Time In and Time Out events'
    );
    dashboard_assert(
        !str_contains(strtolower($dashboard), 'recent attendance—today')
            && !str_contains(strtolower($dashboard), 'recent attendance-today'),
        'Dashboard must not label an unscoped recent-attendance query as today-only'
    );
    dashboard_assert(
        !str_contains(strtolower($dashboard), 'absent / on leave'),
        'Dashboard must not combine absence and leave into a misleading derived number'
    );

    // The redesigned icons are decorative because every card also has text.
    // Removing SVGs from the accessibility tree prevents repeated, noisy
    // announcements while focusable=false fixes legacy browser behavior.
    dashboard_assert(
        str_contains($dashboard, 'ui_icon('),
        'Dashboard must render icons through the shared inline SVG helper'
    );
    preg_match('/return\s+[\'"]<svg\s+class=/i', $functions, $sharedSvgMatch);
    dashboard_assert(
        !empty($sharedSvgMatch),
        'Shared icon helper must return inline SVG markup'
    );
    require_once $root . '/includes/functions.php';
    dashboard_assert_same('45 minutes', ucchr_minutes_label(45), 'Sub-hour attendance durations must remain in minutes');
    dashboard_assert_same('1 hour', ucchr_minutes_label(60), 'Exactly 60 attendance minutes must display as one hour');
    dashboard_assert_same('1 hour 30 minutes', ucchr_minutes_label(90), 'Mixed attendance duration must display hours and minutes');
    dashboard_assert_same('2 hours', ucchr_minutes_label(120), 'Whole multi-hour attendance duration must omit zero minutes');
    $sampleIcon = ui_icon('dashboard');
    preg_match_all('/<svg\b[^>]*>/i', $sampleIcon, $svgMatches);
    $svgTags = $svgMatches[0] ?? [];
    dashboard_assert(count($svgTags) === 1, 'Shared dashboard icon must render as one inline SVG');
    foreach ($svgTags as $index => $svgTag) {
        dashboard_assert(
            preg_match('/\baria-hidden\s*=\s*["\']true["\']/i', $svgTag) === 1,
            'Inline SVG #' . ($index + 1) . ' must set aria-hidden="true"'
        );
        dashboard_assert(
            preg_match('/\bfocusable\s*=\s*["\']false["\']/i', $svgTag) === 1,
            'Inline SVG #' . ($index + 1) . ' must set focusable="false"'
        );
    }

    dashboard_assert(
        preg_match('/class=["\'][^"\']*\btable-scroll\b/i', $dashboard) === 1,
        'Dashboard data table must remain inside a horizontal scroll wrapper'
    );
    preg_match_all(
        '/class=["\'][^"\']*(?:empty-state|admin-dashboard__empty)[^"\']*["\']/i',
        $dashboard,
        $emptyStateMatches
    );
    dashboard_assert(
        count($emptyStateMatches[0] ?? []) >= 2
            && stripos($dashboard, 'No attendance') !== false
            && stripos($dashboard, 'No payroll') !== false,
        'Dashboard must retain separate attendance and payroll empty states'
    );

    // The page name on body scopes futuristic dashboard effects so the login,
    // employee portal, and administrative data-entry pages remain unchanged.
    preg_match('/<body\b[^>]*>/i', $layout, $bodyMatch);
    $bodyTag = (string) ($bodyMatch[0] ?? '');
    dashboard_assert(str_contains($bodyTag, 'app-body'), 'Administrator body must retain app-body');
    $directPageClass = str_contains($bodyTag, 'page-') && str_contains($bodyTag, '$page');
    $assignedPageClass = preg_match(
        '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*=\s*[^;]*page-[^;]*\$page[^;]*;[^<]*<body\b[^>]*\$[a-zA-Z_][a-zA-Z0-9_]*/s',
        $layout
    ) === 1;
    dashboard_assert(
        $directPageClass || $assignedPageClass,
        'Administrator body must include a page-specific class derived from $page'
    );

    // Terminal state must come from the ESP32 heartbeat table and must expose
    // both outcomes. Reject the previous unconditional Online badge.
    dashboard_assert(
        preg_match('/FROM\s+device_status\b/i', $normalizedLayout) === 1,
        'Administrator layout must query the device_status heartbeat table'
    );
    dashboard_assert(
        str_contains(strtolower($layout), 'last_seen'),
        'Terminal state must be derived from its last_seen heartbeat'
    );
    dashboard_assert(
        stripos($layout, 'online') !== false && stripos($layout, 'offline') !== false,
        'Terminal indicator must support both Online and Offline labels'
    );
    dashboard_assert(
        !str_contains($normalizedLayout, '<span class="terminal-status">↗ Terminal Online</span>'),
        'Terminal indicator must not be hard-coded permanently Online'
    );

    // Dashboard-only rules should be namespaced and present in every existing
    // application breakpoint. This protects the rest of the established UI.
    preg_match_all('/\.admin-dashboard(?:__|\s|[.:>+~])/i', $css, $scopedSelectorMatches);
    dashboard_assert(
        count($scopedSelectorMatches[0] ?? []) >= 8,
        'Stylesheet must contain a meaningful set of admin-dashboard-scoped selectors'
    );
    foreach ([1450, 900, 720, 620] as $width) {
        $mediaSection = dashboard_media_section($css, $width);
        dashboard_assert($mediaSection !== '', 'Stylesheet must retain the ' . $width . 'px breakpoint');
        dashboard_assert(
            str_contains($mediaSection, '.admin-dashboard'),
            $width . 'px breakpoint must include admin-dashboard responsive rules'
        );
    }
    dashboard_assert(
        preg_match('/\.table-scroll\s*\{[^}]*overflow-x\s*:\s*auto/i', $css) === 1,
        'Wide dashboard tables must scroll inside their wrapper instead of overflowing the page'
    );

    echo "dashboard_ui_test: PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, "dashboard_ui_test: FAIL - {$error->getMessage()}\n");
    exit(1);
}
