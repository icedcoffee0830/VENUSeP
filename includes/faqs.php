<?php
/* =====================================================================
   FAQs — THE ONE SOURCE for every question on the customer FAQ page.
   Read from the database (faqs + faq_sections, 2026-09-18).

   Included by:
     customer/faq.php              (the ACTIVE rows for the CURRENT refund
                                    policy, per section)
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

   POLICY — the refund switch decides which rows show: 'any' always,
   'refunds_on' / 'refunds_off' only under that state. The switch also
   sets the payment-timing wording (DB-DECISIONS #18), which is why the
   same question can exist twice with different answers.

   FAIL SAFE: if the database cannot be reached the lists come back empty;
   the customer page says the FAQ is temporarily unavailable.
   ===================================================================== */
require_once __DIR__ . '/db.php';
include_once __DIR__ . '/hostel-rooms.php';                                    /* $HOSTEL_RATES, $HOSTEL_CR_LABEL */
ob_start(); include_once __DIR__ . '/pricing.php'; ob_end_clean();             /* $DISCOUNT_PERCENT (its JS block is not needed here) */
require_once __DIR__ . '/refund-policy.php';                                   /* $REFUNDS_ENABLED, $POSTPAY_GRACE_DAYS */

/* [section_key => label], in display order. Empty when the DB is unreachable. */
function faq_sections()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $pdo = venusep_db();
    if ($pdo === null) {
        return $cache;
    }
    try {
        foreach ($pdo->query("SELECT section_key, label FROM faq_sections ORDER BY sort_order, section_key") as $row) {
            $cache[$row['section_key']] = $row['label'];
        }
    } catch (PDOException $e) {
        $cache = [];
    }
    return $cache;
}

/* [section_key => [row, ...]] in sort_order.
     $includeHidden  true = the admin view (hidden rows too)
     $policy         null = every row (admin); true/false = only the rows the
                     customer sees with refunds ON / OFF                        */
function faqs_by_section($includeHidden = false, $policy = null)
{
    $out = [];
    $pdo = venusep_db();
    if ($pdo === null) {
        return $out;
    }
    $where = [];
    if (!$includeHidden) {
        $where[] = "is_active = 1";
    }
    if ($policy !== null) {
        $where[] = "policy IN ('any', '" . ($policy ? 'refunds_on' : 'refunds_off') . "')";
    }
    try {
        $sql = "SELECT id, section_key, question, answer, sort_order, is_active, is_builtin, policy,
                       default_question, default_answer, updated_at
                  FROM faqs" . ($where ? " WHERE " . implode(" AND ", $where) : "") . "
                 ORDER BY section_key, sort_order, id";
        foreach ($pdo->query($sql) as $row) {
            $row['is_active']  = (bool) $row['is_active'];
            $row['is_builtin'] = (bool) $row['is_builtin'];
            $row['is_changed'] = $row['is_builtin']
                && ($row['question'] !== $row['default_question'] || $row['answer'] !== $row['default_answer']);
            $out[$row['section_key']][] = $row;
        }
    } catch (PDOException $e) {
        $out = [];
    }
    return $out;
}

/* The live values a question may quote. Escaped here, once. */
function faq_placeholders()
{
    global $DISCOUNT_PERCENT, $POSTPAY_GRACE_DAYS, $HOSTEL_RATES, $HOSTEL_CR_LABEL;
    $peso = function ($n) { return '&#8369;' . number_format((int) $n); };
    return [
        '{discount}'      => (int) $DISCOUNT_PERCENT . '%',           /* "20%" — the sign is part of the value */
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
    $html = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url = $m[2];
        $safe = preg_match('#^(https?://|[a-z0-9_./-]+\.php(\?[^"<>]*)?(\#[a-z0-9-]+)?|\#[a-z0-9-]+)$#i', $url);
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
