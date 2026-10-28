# Task: separate reports for Bahay Alumni and USeP (Venues + Hostel)

**For Claude (or any developer) picking this up.** VENUSeP is a PHP 8.2 + MariaDB (XAMPP) venue and hostel booking system. Read `DB-DECISIONS.md` before changing anything about the database.

## The change
The Reports page currently mixes every venue into one report. The two offices are run separately, so they need **two separate reports**:

| Report | Venues included (`venues.venue_code`) |
|---|---|
| **Bahay Alumni** | `BAHAY-ALUMNI` |
| **USeP** | `USEP-VENUES` and `USEP-HOSTEL` (event venues + hostel, together) |

Each report has its own stat tiles, charts, venue table, quarter status and printout. Figures from one must never appear in the other.

## Where things are
- **Page:** `admin/Quarterly_Reports.php`.
  - Stat tiles, 5 ApexCharts (revenue growth, revenue by venue, events by venue, discounted bookings, cancelled reservations) and the "Venue Report Details" table.
  - The Reporting Period dropdown narrows the venue charts, tiles and table; the trend charts always show the last 4 quarters.
  - `periodStatus()` shows "In progress" for the current quarter and "Closed" otherwise.
- **Data:** in `includes/bookings.php`:
  - `report_base_sql()` is the one query everything builds on: `event_date`, `collected`, `fell_through`, venue;
  - `report_quarters($count)` gives the last N quarters;
  - `report_by_venue()` and the per-quarter venue breakdown passed to the page as `venueByQuarter`.
- **Printing:**
  - Print styles are the `html.rp-print` rules plus `@media print`, with the `printCharts` array (chart heights on paper) and `prepareForPrint()` / `restoreAfterPrint()` near the bottom of the page script.
  - The USeP letterhead images are `assets/img/reports/print-header.png` and `print-footer.png`.
  - The printout is tuned to fit **one page on A4 and US Letter**. Re-check both after any change.
- **Who sees which venue:**
  - Staff belong to one venue (`staff.venue_id`); admins see all.
  - `staff_venue_scope()` in `includes/auth.php` returns the staff member's venue id, or `null` for admins.
  - The Reports page is admin-only today (`admin/Quarterly_Reports.php`, see `includes/sidebar.php`).

## Suggested approach (confirm with the user first)
1. Add a filter on venue codes to the report functions, e.g. `report_quarters($count, array $venueCodes = null)`, applied inside `report_base_sql()`, so every figure comes from the same filtered rows. Keep the default (`null` = all venues) so nothing else breaks.
2. On the page, add a report switch at the top: **Bahay Alumni | USeP**, e.g. tabs or `?report=bahay` / `?report=usep` in the URL so a link opens the right one. Load only that report's data.
3. Give each report its own title in the page and in the printout, e.g. "Quarterly Report — Bahay Alumni" and "Quarterly Report — USeP Venues & Hostel".
4. **Print:** keep the letterhead. Check it still fits one page on A4 and Letter. The USeP report has 2 venues in the venue charts and Bahay Alumni has 1, so the "by venue" charts may look empty for Bahay Alumni. Consider replacing them with per-room breakdowns.

## Questions to ask the user before building
- Should the switch be tabs on one page, or two separate sidebar entries?
- In the Bahay Alumni report, should the "by venue" charts become **by room** (it has only one venue)?
- Should **staff** get access to their own venue's report, e.g. Bahay Alumni staff see only the Bahay Alumni report? The page is admin-only today.
- Does the CEDU letterhead apply to both reports, or does Bahay Alumni need its own?

## Rules this project follows
- **The user does the committing.** Never commit or change git state.
- Discuss first, then build one item at a time. Verify in a real browser: print to PDF in headless Chrome and look at it.
- Run only `tests/*_test.php` (other files in `tests/` write data). All 15 should pass.
- This should need **no database change**. If one becomes necessary, add `venusep_migration_14.sql` (idempotent), mirror it in `venusep_schema.sql`, and note it in `DB-DECISIONS.md`.
- Use the existing `venusepAlert` / `venusepConfirm` modals, never `alert()` / `confirm()`.
- Record the decision in `DB-DECISIONS.md` when done.
