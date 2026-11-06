<?php
/* =====================================================================
   FAQs — THE ONE SOURCE for every question on the customer FAQ page.
   Read from the database (faqs + faq_sections, 2026-09-18).

   Included by:
     customer/faq.php              (the ACTIVE rows each office's refund
                                    switch shows, per section: faq_row_for)
     admin/faq-management.php      (every row, with a preview switch:
                                    "as customers see it with refunds ON / OFF")
     admin/faq-save.php            (the only writer)

   Two kinds of rows, one table:
     built-in  (is_builtin = 1) — shipped with the system, seeded from
               venusep_schema.sql. Admins may EDIT and HIDE them, never
               delete; the original wording is kept in default_question /
               default_answer so "Restore original" always works.
     added     (is_builtin = 0) — the admins' own questions.

   ANSWER FORMAT — plain text with a little markup, rendered by
   faq_answer_html(); HTML in the text is never interpreted:
     **bold**              -> <strong>
     [text](booking-history.php) -> <a>   (relative pages or http(s) only)
     - item                -> a bulleted list (one "- " line per item)
     blank line            -> new paragraph; single line break kept
     {discount} {grace_days} {rate_communal} {rate_private}
     {cr_communal} {cr_private}  -> the live values (pricing.php,
                                    refund-policy.php, hostel-rooms.php)

   POLICY — the refund switches (one per office, DB-DECISIONS 28) decide
   which rows show: 'any' always, 'refunds_on' / 'refunds_off' while an
   office the row is about is in that state. The switch also sets the
   payment-timing wording (DB-DECISIONS #18), which is why the same
   question can exist twice with different answers.

   FAIL SAFE: if the database cannot be reached the lists come back empty;
   the customer page says the FAQ is temporarily unavailable.
   ===================================================================== */
require_once __DIR__ . '/db.php';
/* Laravel: through legacy(), so the files' variables reach the registry (and
   this file's scope) — a bare include here would hide them from later callers. */
legacy('hostel-rooms');                                                        /* $HOSTEL_RATES, $HOSTEL_CR_LABEL */
ob_start(); legacy('pricing'); ob_end_clean();                                 /* $DISCOUNT_PERCENT (its JS block is not needed here) */
legacy('refund-policy');                                                       /* $REFUNDS_ENABLED, $POSTPAY_GRACE_DAYS */

/* [section_key => label], in display order. Empty when the DB is unreachable. */
function faq_sections()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (venusep_db() === null) {
        return $cache;
    }
    try {
        foreach (\App\Queries\FaqQueries::sections() as $row) {
            $cache[$row['section_key']] = $row['label'];
        }
    } catch (PDOException $e) {
        sql_failed($e);
        $cache = [];
    }
    return $cache;
}

/* [section_key => [row, ...]] in sort_order, every policy.
     $includeHidden  true = the admin view (hidden rows too)                    */
function faqs_by_section($includeHidden = false)
{
    $out = [];
    if (venusep_db() === null) {
        return $out;
    }
    try {
        foreach (\App\Queries\FaqQueries::all($includeHidden) as $row) {
            $row['is_active']  = (bool) $row['is_active'];
            $row['is_builtin'] = (bool) $row['is_builtin'];
            $row['is_changed'] = $row['is_builtin']
                && ($row['question'] !== $row['default_question'] || $row['answer'] !== $row['default_answer']);
            $out[$row['section_key']][] = $row;
        }
    } catch (PDOException $e) {
        sql_failed($e);
        $out = [];
    }
    return $out;
}

/* Does the customer see this row (one refund switch per office, DB-DECISIONS 28)?
   null = no; '' = yes; else yes, naming the offices it speaks for. An 'any' row always
   shows. A 'refunds_on' / 'refunds_off' row speaks for the offices of its section
   (hostel: the hostel; booking, cash: the event offices; the rest: all) whose switch is
   in that state. It names them only when the section's offices differ.
   $offices: office_all() rows, the ones this page shows. */
function faq_row_for(array $row, array $offices): ?string
{
    if ($row['policy'] === 'any') {
        return '';
    }
    $about = array_filter($offices, fn ($o) => match ($row['section_key']) {
        'hostel'          => $o['type'] === 'hostel',
        'booking', 'cash' => $o['type'] !== 'hostel',
        default           => true,
    });
    $for = array_filter($about, fn ($o) => $o['refunds'] === ($row['policy'] === 'refunds_on'));
    if (!$for) {
        return null;
    }
    return count($for) === count($about) ? '' : \Illuminate\Support\Arr::join(array_column($for, 'name'), ', ', ' and ');
}

/* The live values a question may quote. Escaped here, once. */
function faq_placeholders()
{
    /* Laravel: those files' variables live in legacy()'s registry, not in globals */
    $legacy = legacy('hostel-rooms', 'refund-policy');
    $POSTPAY_GRACE_DAYS = $legacy['POSTPAY_GRACE_DAYS'];
    $HOSTEL_RATES = $legacy['HOSTEL_RATES'];
    $HOSTEL_CR_LABEL = $legacy['HOSTEL_CR_LABEL'];
    /* usep_peso()'s rule (pricing.php — not loaded here, it prints a <script>):
       centavos only when there are any, so 350.50 is "₱350.50" and 350 is "₱350" */
    $peso = function ($n) { return '&#8369;' . number_format((float) $n, fmod((float) $n, 1.0) == 0.0 ? 0 : 2); };
    return [
        '{discount}'      => office_discount_label(),           /* "20%" — the sign is part of the value */
        '{grace_days}'    => (int) $POSTPAY_GRACE_DAYS,
        '{rate_communal}' => $peso(isset($HOSTEL_RATES['communal']) ? $HOSTEL_RATES['communal'] : 0),
        '{rate_private}'  => $peso(isset($HOSTEL_RATES['private']) ? $HOSTEL_RATES['private'] : 0),
        '{cr_communal}'   => htmlspecialchars(isset($HOSTEL_CR_LABEL['communal']) ? $HOSTEL_CR_LABEL['communal'] : 'Communal CR', ENT_QUOTES, 'UTF-8'),
        '{cr_private}'    => htmlspecialchars(isset($HOSTEL_CR_LABEL['private']) ? $HOSTEL_CR_LABEL['private'] : 'Private CR', ENT_QUOTES, 'UTF-8'),
    ];
}

/* A question: escaped, placeholders filled. */
function faq_question_html($question)
{
    return strtr(htmlspecialchars((string) $question, ENT_QUOTES, 'UTF-8'), faq_placeholders());
}

/* An answer: escaped first (so typed HTML is shown, not run), then the
   little markup above, then the placeholders.
   $forEditor = true paints each placeholder as a non-editable chip showing
   its current value (the admin editor); the chip carries the placeholder so
   saving keeps it live. */
function faq_answer_html($answer, $forEditor = false)
{
    $text  = str_replace("\r\n", "\n", trim((string) $answer));
    $lines = explode("\n", htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    $html  = '';
    $para  = [];
    $list  = [];
    $flushPara = function () use (&$para, &$html) {
        if ($para) { $html .= '<p class="fq-a">' . implode('<br>', $para) . '</p>'; $para = []; }
    };
    $flushList = function () use (&$list, &$html) {
        if ($list) { $html .= '<div class="fq-a"><ul><li>' . implode('</li><li>', $list) . '</li></ul></div>'; $list = []; }
    };
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') { $flushPara(); $flushList(); continue; }
        if (strpos($line, '- ') === 0) { $flushPara(); $list[] = substr($line, 2); continue; }
        $flushList();
        $para[] = $line;
    }
    $flushPara();
    $flushList();
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) use ($forEditor) {
        $url = $m[2];
        $safe = preg_match('#^(https?://[^"<>]+|[a-z0-9_./-]+\.php(\?[^"<>]*)?(\#[a-z0-9-]+)?|\#[a-z0-9-]+)$#i', $url);
        if ($safe && !$forEditor) {
            $url = faq_link_url($url);   /* Laravel: stored "page.php" links -> the page's route */
            if ($url === null) {
                return $m[1];            /* counter mode, customer-only page: the words, no link */
            }
        }
        return $safe ? '<a href="' . $url . '">' . $m[1] . '</a>' : $m[1];
    }, $html);
    $values = faq_placeholders();
    if ($forEditor) {
        foreach ($values as $token => $value) {
            $values[$token] = '<span class="fq-token" contenteditable="false" data-token="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">' . $value . '</span>';
        }
    }
    return strtr($html, $values);
}

/* Laravel: the answers store the old relative page links ("booking-history.php",
   "faq.php#gcash", "../customer/booking-history.php") — a customer page each. On
   display they become that page's route; the editor keeps the stored form so a
   save round-trips it. A page with no route keeps its name without ".php".
   COUNTER MODE (staff at the counter, ?counter=1): only the booking pages and
   the FAQ work there, and they keep the flag; any other page is the customer's
   own (booking history, transaction history, profile…) and would land staff on
   the customer sign-in, so it returns null and the answer shows just the words. */
function faq_link_url($url)
{
    if (!preg_match('#^(?:[a-z0-9_./-]*/)?([a-z0-9_-]+)\.php(\?[^"<>\#]*)?(\#[a-z0-9-]+)?$#i', $url, $p)) {
        return $url;                                     /* http(s):// or a bare #anchor */
    }
    $renames = ['venusep_venue_booking' => 'customer.venues'];
    $name = isset($renames[$p[1]]) ? $renames[$p[1]] : 'customer.' . $p[1];
    $query = isset($p[2]) ? $p[2] : '';
    if (function_exists('counter_mode') && counter_mode()) {
        if (!in_array($name, ['customer.venues', 'customer.room-reservation', 'customer.hostel-reservation', 'customer.faq'], true)) {
            return null;
        }
        $query = ($query === '' ? '?' : $query . '&') . 'counter=1';
    }
    $base = \Illuminate\Support\Facades\Route::has($name) ? route($name) : $p[1];
    return $base . $query . (isset($p[3]) ? $p[3] : '');
}
