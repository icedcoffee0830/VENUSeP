<?php

namespace App\Console\VenusepCheck;

/* The run, written to storage/app/venusep-check/<timestamp>.json and .html.
   The HTML file is self-contained: open it straight from the folder. */
final class Report
{
    public const LABELS = [
        'ok' => 'ok', 'warn' => 'warnings', 'fail' => 'failing', 'not_ported' => 'not ported',
        'needs_params' => 'needs params', 'skipped' => 'skipped',
    ];

    public static function summary(array $results): array
    {
        $counts = array_fill_keys(array_keys(self::LABELS), 0);
        foreach ($results as $r) {
            $counts[$r['result']] = ($counts[$r['result']] ?? 0) + 1;
        }

        return $counts;
    }

    public static function summaryLine(array $counts): string
    {
        $parts = [];
        foreach (self::LABELS as $key => $label) {
            if ($counts[$key] > 0 || in_array($key, ['ok', 'fail', 'not_ported', 'needs_params'], true)) {
                $parts[] = "{$counts[$key]} {$label}";
            }
        }

        return implode(' · ', $parts);
    }

    /** @return array{0: string, 1: string} json path, html path */
    public static function write(array $data): array
    {
        $dir = storage_path('app' . DIRECTORY_SEPARATOR . 'venusep-check');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $stamp = date('Y-m-d_His');
        $base = $dir . DIRECTORY_SEPARATOR . $stamp;
        for ($n = 2; is_file($base . '.json'); $n++) {
            $base = $dir . DIRECTORY_SEPARATOR . $stamp . '-' . $n;
        }
        file_put_contents($base . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        file_put_contents($base . '.html', self::html($data));

        return [$base . '.json', $base . '.html'];
    }

    private static function html(array $data): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach ($data['results'] as $r) {
            $problems = '';
            foreach ($r['problems'] as [$level, $text]) {
                $problems .= '<li class="p-' . $e($level) . '">' . $e($text) . '</li>';
            }
            $status = $r['status'] === null ? '—' : $e($r['status']);
            if ($r['redirect']) {
                $status .= ' <span class="to">→ ' . $e($r['redirect']) . '</span>';
            }
            $rows .= '<tr data-result="' . $e($r['result']) . '">'
                . '<td><code>' . $e($r['route']) . '</code><div class="url">' . $e($r['url']) . '</div></td>'
                . '<td>' . $e($r['role']) . '</td>'
                . '<td><span class="badge b-' . $e($r['result']) . '">' . $e(self::LABELS[$r['result']] ?? $r['result']) . '</span></td>'
                . '<td>' . $status . '</td>'
                . '<td class="num">' . $e($r['ms']) . '</td>'
                . '<td>' . ($problems ? '<ul>' . $problems . '</ul>' : '') . '</td></tr>';
        }
        $buttons = '<button type="button" data-f="all" aria-pressed="true">all ' . count($data['results']) . '</button>';
        foreach ($data['summary'] as $key => $n) {
            if ($n > 0) {
                $buttons .= '<button type="button" data-f="' . $e($key) . '" aria-pressed="false">' . $e(self::LABELS[$key]) . ' ' . $n . '</button>';
            }
        }
        $notes = '';
        foreach ($data['notes'] as $note) {
            $notes .= '<li>' . $e($note) . '</li>';
        }
        $browser = '';
        if (!empty($data['browser'])) {
            $browser = '<h2>Browser pass</h2><pre>' . $e(json_encode($data['browser'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VENUSeP check {$e($data['generated_at'])}</title>
<style>
:root{--bg:#faf8f5;--fg:#1f1e1e;--muted:#6e6a64;--line:#e4dfd8;--card:#fff;--ok:#1f7a3f;--warn:#9a6200;--fail:#b3261e;--np:#5b5b8f;--skip:#777}
@media (prefers-color-scheme:dark){:root{--bg:#161514;--fg:#ece9e4;--muted:#a39e96;--line:#34312d;--card:#1f1e1c;--ok:#5cc184;--warn:#e3a646;--fail:#ff8a80;--np:#a6a6e0;--skip:#aaa}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;padding:16px}
h1{font-size:20px;margin:0 0 4px}h2{font-size:16px;margin:24px 0 8px}
.meta{color:var(--muted);margin:0 0 12px}
.filters{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0}
.filters button{font:inherit;padding:4px 10px;border:1px solid var(--line);border-radius:999px;background:var(--card);color:var(--fg);cursor:pointer}
.filters button[aria-pressed=true]{background:var(--fg);color:var(--bg)}
.wrap{overflow-x:auto;border:1px solid var(--line);border-radius:8px;background:var(--card)}
table{border-collapse:collapse;width:100%;min-width:720px}
th,td{text-align:left;vertical-align:top;padding:6px 10px;border-bottom:1px solid var(--line)}
th{position:sticky;top:0;background:var(--card);font-weight:600}
td.num{text-align:right;font-variant-numeric:tabular-nums}
.url,.to{color:var(--muted);font-size:12px;word-break:break-all}
ul{margin:0;padding-left:16px}li{word-break:break-word}
.p-fail{color:var(--fail)}.p-warn,.p-needs{color:var(--warn)}
.badge{display:inline-block;padding:1px 8px;border-radius:999px;font-size:12px;border:1px solid currentColor;white-space:nowrap}
.b-ok{color:var(--ok)}.b-warn,.b-needs_params{color:var(--warn)}.b-fail{color:var(--fail)}.b-not_ported{color:var(--np)}.b-skipped{color:var(--skip)}
pre{white-space:pre-wrap;background:var(--card);border:1px solid var(--line);padding:10px;border-radius:8px}
</style>
</head>
<body>
<h1>VENUSeP page check</h1>
<p class="meta">{$e($data['generated_at'])} · database <code>{$e($data['database'])}</code> · {$e($data['summary_line'])} · exit {$e($data['exit_code'])}</p>
<ul class="meta">{$notes}</ul>
<div class="filters" role="group" aria-label="Filter by result">{$buttons}</div>
<div class="wrap"><table>
<thead><tr><th>Route</th><th>Role</th><th>Result</th><th>Status</th><th>ms</th><th>Problems</th></tr></thead>
<tbody>{$rows}</tbody>
</table></div>
{$browser}
<script>
document.querySelectorAll('.filters button').forEach(function (b) {
  b.addEventListener('click', function () {
    document.querySelectorAll('.filters button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
    var f = b.dataset.f;
    document.querySelectorAll('tbody tr').forEach(function (tr) { tr.hidden = f !== 'all' && tr.dataset.result !== f; });
  });
});
</script>
</body>
</html>
HTML;
    }
}
