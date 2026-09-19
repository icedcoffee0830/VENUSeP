-- ============================================================================
-- VENUSeP Database Schema
-- Target: MySQL 8.0+ / MariaDB 10.4+ (XAMPP)
-- Built to match DB-DECISIONS.md (locked 2026-07-19, refund switch added
-- 2026-09-16, payment timing tied to the switch 2026-09-17, admin FAQs
-- 2026-09-18). Where this and the first-draft schema differ, DB-DECISIONS.md
-- is the authority.
--
-- SCOPE OF THIS FILE: tables + constraints + seed data, then the logic layer
-- (functions, triggers, procedures, views) and the application notes.
--
-- WHAT THE APP ACTUALLY USES TODAY: only users/customers (the two logins) and
-- system_settings/system_settings_history + the users re-auth lockout columns
-- (the admin refund switch). Every other table is built and seeded, waiting for
-- its page to be wired.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS venusep
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE venusep;
SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- ============================================================================
-- 1. CONFIG  (system_settings kept + improved — DB-DECISIONS #10)
--    Home for tunable, staff-editable values. `updated_by_user_id` added so we
--    know who changed a money/policy value.
-- ============================================================================
CREATE TABLE system_settings (
    setting_key         VARCHAR(100) PRIMARY KEY,
    setting_value       VARCHAR(255) NOT NULL,
    value_type          ENUM('string','integer','decimal','boolean','json') NOT NULL DEFAULT 'string',
    description         VARCHAR(500) NULL,
    updated_by_user_id  BIGINT UNSIGNED NULL,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
-- FK to users added after users exists (see ALTER at end of section 2).

-- ============================================================================
-- 2. ACCOUNTS & PEOPLE
--    customers is DECOUPLED from users: standalone id, nullable user_id, so a
--    WALK-IN is a customer with no login (DB-DECISIONS #4).
-- ============================================================================
CREATE TABLE users (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email               VARCHAR(190) NOT NULL,
    username            VARCHAR(80) NULL,
    password_hash       VARCHAR(255) NOT NULL,
    account_type        ENUM('customer','staff','admin') NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    email_verified_at   DATETIME NULL,
    last_login_at       DATETIME NULL,
    -- Password RE-ENTRY lockout for sensitive admin actions (the refund switch).
    -- Stored on the account, not the session, so clearing cookies or switching
    -- browsers cannot reset it. 5 wrong in a row = one lock; each lock is longer
    -- (10s, 30s, 1m, 5m, then 15m max). A correct password resets all three.
    reauth_failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- wrong entries since the last lock/success
    reauth_lock_level   TINYINT UNSIGNED NOT NULL DEFAULT 0,       -- how many locks so far (picks the duration)
    reauth_locked_until DATETIME NULL,                             -- NULL or past = not locked
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_users_email UNIQUE (email),
    CONSTRAINT uq_users_username UNIQUE (username)
) ENGINE=InnoDB;

ALTER TABLE system_settings
    ADD CONSTRAINT fk_settings_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- A settings change is an EVENT, not an overwrite (DB-TRANSITION: discount rate
-- setting). system_settings holds only the current value; this keeps who changed
-- what, when, from what, to what. Used by the refund switch; the discount rate
-- uses it too once it is wired.
CREATE TABLE system_settings_history (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key         VARCHAR(100) NOT NULL,
    old_value           VARCHAR(255) NULL,
    new_value           VARCHAR(255) NOT NULL,
    change_note         VARCHAR(500) NULL,
    changed_by_user_id  BIGINT UNSIGNED NULL,
    changed_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_history_key
        FOREIGN KEY (setting_key) REFERENCES system_settings(setting_key)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_settings_history_user
        FOREIGN KEY (changed_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE customers (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             BIGINT UNSIGNED NULL,          -- NULL = walk-in (no login)
    full_name           VARCHAR(190) NOT NULL,
    phone               VARCHAR(30) NULL,
    address             VARCHAR(500) NULL,
    university_id_no    VARCHAR(80) NULL,
    -- PROFILE PICTURE ONLY. Never an ID, a receipt or any other document — those
    -- are booking_documents rows, stored outside the web root and served through
    -- a permission check. This path is served straight out of assets/ like any
    -- other image, which is exactly why nothing sensitive may be put here.
    photo_path          VARCHAR(500) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_customers_user UNIQUE (user_id),     -- multiple NULLs allowed; a login maps to one customer
    CONSTRAINT fk_customers_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;
-- NOTE: affiliation (USeP-affiliated) is NOT stored here — it is per-booking and
-- re-verified every booking (DB-DECISIONS #3), so it lives on `bookings`.

CREATE TABLE staff (
    user_id             BIGINT UNSIGNED PRIMARY KEY,
    full_name           VARCHAR(190) NOT NULL,
    employee_no         VARCHAR(80) NULL,              -- kept + to be added to UI (DB-DECISIONS #13)
    position_role       VARCHAR(120) NULL,             -- renamed from position_title (#12)
    phone               VARCHAR(30) NULL,
    photo_path          VARCHAR(500) NULL,             -- profile picture only (same rule as customers.photo_path)
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_staff_employee_no UNIQUE (employee_no),
    CONSTRAINT fk_staff_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- 3. VENUES, ROOMS, HOSTEL BEDS, MEDIA, MAINTENANCE, GCASH
--    Amenities/attributes + "at a glance" facts are PHP-coded, NOT tables
--    (DB-DECISIONS #9) — so there is no amenities/room_amenities table.
-- ============================================================================
CREATE TABLE venues (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue_code          VARCHAR(30) NOT NULL,
    name                VARCHAR(150) NOT NULL,
    venue_type          ENUM('event','hostel') NOT NULL,   -- renamed from venue_kind (#12)
    description         TEXT NULL,
    cover_photo         VARCHAR(500) NULL,                 -- relative path, set by admin/venue-photo-api.php (one photo per venue, unlike room_media's gallery)
    address             VARCHAR(500) NULL,
    contact_phone       VARCHAR(30) NULL,
    contact_email       VARCHAR(190) NULL,                 -- kept + to be added to UI (#13)
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_venues_code UNIQUE (venue_code),
    CONSTRAINT uq_venues_name UNIQUE (name)
) ENGINE=InnoDB;

CREATE TABLE rooms (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue_id            BIGINT UNSIGNED NOT NULL,
    room_code           VARCHAR(40) NOT NULL,
    name                VARCHAR(150) NOT NULL,
    room_type           ENUM('event','hostel') NOT NULL,   -- must match the venue's venue_type (app-enforced for now)
    description         TEXT NULL,
    -- Which amenities this room has, as a list of KEYS: ["stage_podium","aircon"].
    -- The amenity VOCABULARY (label + icon per key) stays PHP-coded, so this adds
    -- no amenities table and DB-DECISIONS #9 still holds. Keys rather than labels
    -- so rewording a label in PHP updates every room at once instead of orphaning
    -- them. On MariaDB `JSON` is an alias for LONGTEXT — the CHECK below is what
    -- actually enforces well-formed JSON.
    amenities           JSON NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,      -- FALSE = retire/hide a room (distinct from a maintenance window)
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_rooms_amenities_json CHECK (amenities IS NULL OR JSON_VALID(amenities)),
    CONSTRAINT uq_rooms_code UNIQUE (room_code),
    CONSTRAINT uq_rooms_venue_name UNIQUE (venue_id, name),
    CONSTRAINT fk_rooms_venue
        FOREIGN KEY (venue_id) REFERENCES venues(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE event_room_details (
    room_id             BIGINT UNSIGNED PRIMARY KEY,
    attendee_capacity   INT UNSIGNED NOT NULL,
    fee_per_day         DECIMAL(12,2) NOT NULL,
    CONSTRAINT chk_event_room_capacity CHECK (attendee_capacity > 0),
    CONSTRAINT chk_event_room_fee CHECK (fee_per_day >= 0),
    CONSTRAINT fk_event_room_details_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE hostel_room_details (
    room_id                     BIGINT UNSIGNED PRIMARY KEY,
    cr_type                     ENUM('communal','private') NOT NULL,
    rate_per_head_per_night     DECIMAL(12,2) NOT NULL,
    check_in_time               TIME NULL,
    check_out_time              TIME NULL,
    CONSTRAINT chk_hostel_room_rate CHECK (rate_per_head_per_night >= 0),
    CONSTRAINT fk_hostel_room_details_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- Exact-bed model (DB-DECISIONS #7). A room's bed capacity is COUNT(hostel_beds);
-- a single broken bunk is is_active = FALSE.
CREATE TABLE hostel_beds (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id             BIGINT UNSIGNED NOT NULL,
    bed_code            VARCHAR(40) NOT NULL,
    bed_label           VARCHAR(80) NOT NULL,
    display_order       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_hostel_bed_code UNIQUE (room_id, bed_code),
    CONSTRAINT uq_hostel_bed_label UNIQUE (room_id, bed_label),
    CONSTRAINT fk_hostel_beds_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE room_media (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id             BIGINT UNSIGNED NOT NULL,
    media_type          ENUM('photo','panorama_360') NOT NULL,
    file_path           VARCHAR(500) NOT NULL,
    original_filename   VARCHAR(255) NULL,
    caption             VARCHAR(255) NULL,
    display_order       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    mime_type           VARCHAR(100) NULL,
    file_size_bytes     BIGINT UNSIGNED NULL,
    sha256_hash         CHAR(64) NULL,
    uploaded_by_user_id BIGINT UNSIGNED NULL,
    uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_room_media_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_room_media_uploader
        FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- Maintenance = a dated closure on a room (DB-DECISIONS #11). System-wide: any
-- room, venue OR hostel. blocks_booking: TRUE = HARD (cannot book), FALSE =
-- MEDIUM (bookable, customer just sees a notice). until_date NULL = indefinite.
CREATE TABLE maintenance_windows (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id             BIGINT UNSIGNED NOT NULL,
    from_date           DATE NOT NULL,
    until_date          DATE NULL,                         -- NULL = indefinite
    reason              VARCHAR(500) NOT NULL,
    blocks_booking      BOOLEAN NOT NULL DEFAULT TRUE,      -- TRUE = HARD, FALSE = MEDIUM
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_maintenance_dates CHECK (until_date IS NULL OR until_date >= from_date),
    CONSTRAINT fk_maintenance_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_maintenance_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- One GCash account per venue (DB-DECISIONS #5). The generated active_venue_id +
-- UNIQUE enforces at most ONE active account per venue while keeping history.
CREATE TABLE gcash_accounts (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue_id            BIGINT UNSIGNED NOT NULL,
    account_name        VARCHAR(150) NOT NULL,
    mobile_number       VARCHAR(30) NOT NULL,
    note                VARCHAR(500) NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    valid_from          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    valid_until         DATETIME NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    active_venue_id     BIGINT UNSIGNED GENERATED ALWAYS AS
                        (CASE WHEN is_active = 1 AND valid_until IS NULL THEN venue_id ELSE NULL END) STORED,
    CONSTRAINT uq_gcash_one_active_per_venue UNIQUE (active_venue_id),
    CONSTRAINT fk_gcash_accounts_venue
        FOREIGN KEY (venue_id) REFERENCES venues(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_gcash_accounts_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================================
-- 4. STATUS LOOKUPS  (two tables, code key, staff + customer labels — #1)
-- ============================================================================
CREATE TABLE reservation_statuses (
    code                VARCHAR(40) PRIMARY KEY,
    staff_label         VARCHAR(120) NOT NULL,
    customer_label      VARCHAR(120) NOT NULL,
    sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_terminal         BOOLEAN NOT NULL DEFAULT FALSE
) ENGINE=InnoDB;

CREATE TABLE payment_statuses (
    code                VARCHAR(40) PRIMARY KEY,
    staff_label         VARCHAR(120) NOT NULL,
    customer_label      VARCHAR(120) NOT NULL,
    sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ============================================================================
-- 5. BOOKINGS  (one table; customer submit = INSERT, admin queue = SELECT)
--    Discount is snapshotted here; total_amount is the DISCOUNTED total the
--    checker validates. Affiliation is per-booking (DB-DECISIONS #2, #3).
-- ============================================================================
CREATE TABLE bookings (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id         BIGINT UNSIGNED NOT NULL,
    room_id             BIGINT UNSIGNED NOT NULL,
    booking_type        ENUM('venue','hostel') NOT NULL,     -- must match room_type (app-enforced for now)
    reservation_status  VARCHAR(40) NOT NULL DEFAULT 'pending',
    payment_status      VARCHAR(40) NOT NULL DEFAULT 'locked',
    payment_method      ENUM('gcash','cash') NULL,           -- GCash + Cash only (DB-DECISIONS #6)
    -- affiliation (per booking, re-verified each time)
    is_usep_affiliated  BOOLEAN NOT NULL DEFAULT FALSE,       -- the customer's claim for THIS booking
    affiliation_verified BOOLEAN NOT NULL DEFAULT FALSE,      -- staff verified the USeP ID for THIS booking
    -- pricing (all snapshotted at booking time)
    room_price          DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- renamed from rate_snapshot; pre-discount unit price
    discount_percent    DECIMAL(5,2) NOT NULL DEFAULT 0.00,   -- % actually applied (frozen; live % lives in system_settings)
    discount_amount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- DISCOUNTED total; this is what the checker validates
    -- Refund policy snapshot (2026-09-16): copied from system_settings.refunds_enabled
    -- when the booking is MADE, and never changed afterwards. The customer is held
    -- to the policy they agreed to — flipping the switch later neither grants nor
    -- removes refunds on this booking. Covers customer-requested refunds only; a
    -- closure by USeP (reservation_status 'disrupted') is refundable regardless.
    -- 2026-09-17: the same snapshot fixes WHEN the booking is paid —
    --   TRUE  = pre-pay : pay after approval, by 1 day before the first day
    --   FALSE = post-pay: pay after the last day, within postpay_grace_days
    -- (fn_payment_deadline / sp_approve_booking read it; nothing else decides).
    refunds_allowed     BOOLEAN NOT NULL DEFAULT FALSE,
    current_deadline_at DATETIME NULL,                     -- the pay-by moment for the booking's policy (see above)
    customer_notes      TEXT NULL,
    staff_notes         TEXT NULL,
    submitted_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at         DATETIME NULL,
    completed_at        DATETIME NULL,
    cancelled_at        DATETIME NULL,
    updated_by_user_id  BIGINT UNSIGNED NULL,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_bookings_room_price CHECK (room_price >= 0),
    CONSTRAINT chk_bookings_discount_pct CHECK (discount_percent >= 0 AND discount_percent <= 100),
    CONSTRAINT chk_bookings_total CHECK (total_amount >= 0),
    CONSTRAINT fk_bookings_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_res_status
        FOREIGN KEY (reservation_status) REFERENCES reservation_statuses(code)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_pay_status
        FOREIGN KEY (payment_status) REFERENCES payment_statuses(code)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---- Venue booking: header (no single time — per-day times live in slots, #8)
CREATE TABLE venue_booking_details (
    booking_id          BIGINT UNSIGNED PRIMARY KEY,
    event_name          VARCHAR(190) NOT NULL,
    purpose             TEXT NULL,
    start_date          DATE NOT NULL,
    end_date            DATE NOT NULL,
    attendee_count      INT UNSIGNED NOT NULL,
    CONSTRAINT chk_venue_booking_dates CHECK (end_date >= start_date),
    CONSTRAINT chk_venue_booking_attendees CHECK (attendee_count > 0),
    CONSTRAINT fk_venue_booking_details_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- One row per booked day, each with its OWN start/end time (authoritative
-- per-day schedule). released_at frees the hold. Venue-overlap prevention is
-- app-level until the guard proc is added (see deferred notes).
CREATE TABLE venue_booking_slots (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id          BIGINT UNSIGNED NOT NULL,
    room_id             BIGINT UNSIGNED NOT NULL,
    slot_date           DATE NOT NULL,
    start_time          TIME NOT NULL,
    end_time            TIME NOT NULL,
    released_at         DATETIME NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_venue_slot_times CHECK (end_time > start_time),
    CONSTRAINT uq_venue_slot_booking UNIQUE (booking_id, slot_date, start_time, end_time),
    CONSTRAINT fk_venue_slots_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_venue_slots_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ---- Hostel booking: stay header + CEDU/POS + OR + check-in
CREATE TABLE hostel_booking_details (
    booking_id                   BIGINT UNSIGNED PRIMARY KEY,
    check_in_date                DATE NOT NULL,
    check_out_date               DATE NOT NULL,
    pos_number                   VARCHAR(100) NULL,          -- from CEDU; NULL until recorded (payment locked)
    pos_recorded_at              DATETIME NULL,
    official_receipt_no          VARCHAR(100) NULL,          -- OR is a DOCUMENT, recorded AFTER confirmation
    official_receipt_recorded_at DATETIME NULL,
    checked_in                   BOOLEAN NOT NULL DEFAULT FALSE,
    checked_in_at                DATETIME NULL,
    CONSTRAINT chk_hostel_booking_dates CHECK (check_out_date > check_in_date),
    CONSTRAINT fk_hostel_booking_details_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- Renamed from booking_occupants (#7). Gender keeps 'other', drops
-- 'prefer_not_to_say'. One row per named guest / per bed.
CREATE TABLE hostel_occupants (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id          BIGINT UNSIGNED NOT NULL,
    full_name           VARCHAR(190) NOT NULL,
    gender              ENUM('male','female','other') NOT NULL,
    is_primary_guest    BOOLEAN NOT NULL DEFAULT FALSE,
    contact_phone       VARCHAR(30) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_hostel_occupant_pair UNIQUE (id, booking_id),   -- for the composite FK below
    CONSTRAINT fk_hostel_occupants_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- One occupant -> one exact bed for the stay.
CREATE TABLE bed_reservations (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id          BIGINT UNSIGNED NOT NULL,
    occupant_id         BIGINT UNSIGNED NOT NULL,
    bed_id              BIGINT UNSIGNED NOT NULL,
    check_in_date       DATE NOT NULL,
    check_out_date      DATE NOT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_bed_reservation_dates CHECK (check_out_date > check_in_date),
    CONSTRAINT uq_bed_reservation_occupant UNIQUE (booking_id, occupant_id),
    CONSTRAINT fk_bed_reservation_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bed_reservation_occupant
        FOREIGN KEY (occupant_id, booking_id) REFERENCES hostel_occupants(id, booking_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bed_reservation_bed
        FOREIGN KEY (bed_id) REFERENCES hostel_beds(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- One row per occupied NIGHT. UNIQUE(active_bed_id, night_date) is the last-bed
-- race guard: two people can't hold the same bed the same night. active_bed_id
-- goes NULL when released, so freed nights don't block (multiple NULLs allowed).
CREATE TABLE bed_reservation_nights (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bed_reservation_id  BIGINT UNSIGNED NOT NULL,
    booking_id          BIGINT UNSIGNED NOT NULL,
    bed_id              BIGINT UNSIGNED NOT NULL,
    night_date          DATE NOT NULL,
    released_at         DATETIME NULL,
    active_bed_id       BIGINT UNSIGNED GENERATED ALWAYS AS
                        (CASE WHEN released_at IS NULL THEN bed_id ELSE NULL END) STORED,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_bed_reservation_night UNIQUE (bed_reservation_id, night_date),
    CONSTRAINT uq_active_bed_night UNIQUE (active_bed_id, night_date),
    CONSTRAINT fk_bed_nights_assignment
        FOREIGN KEY (bed_reservation_id) REFERENCES bed_reservations(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bed_nights_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_bed_nights_bed
        FOREIGN KEY (bed_id) REFERENCES hostel_beds(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================================
-- 6. PAYMENTS, RECEIPTS, DOCUMENTS, REFUNDS, TIMELINE
-- ============================================================================
CREATE TABLE payments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id          BIGINT UNSIGNED NOT NULL,
    payment_method      ENUM('gcash','cash') NOT NULL,
    amount              DECIMAL(12,2) NOT NULL,
    payment_record_status ENUM('submitted','under_review','confirmed','rejected','refunded') NOT NULL DEFAULT 'submitted',
    paid_at             DATETIME NULL,
    confirmed_at        DATETIME NULL,
    confirmed_by_user_id BIGINT UNSIGNED NULL,
    notes               TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_payment_amount CHECK (amount > 0),
    CONSTRAINT fk_payments_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_payments_confirmer
        FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- verdict: accepted / rejected / manual_review (DB-DECISIONS #6). reference_number
-- + file hash are UNIQUE across ALL receipts (anti-reuse). attempt_no supports
-- the 5-attempt rule (enforced in the app).
CREATE TABLE gcash_receipts (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id          BIGINT UNSIGNED NOT NULL,
    booking_id          BIGINT UNSIGNED NOT NULL,
    attempt_no          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    file_path           VARCHAR(500) NOT NULL,
    original_filename   VARCHAR(255) NULL,
    reference_number    VARCHAR(100) NOT NULL,
    receiver_name       VARCHAR(150) NULL,
    receiver_number     VARCHAR(30) NULL,
    amount_centavos     BIGINT UNSIGNED NULL,
    receipt_datetime    DATETIME NULL,
    sha256_hash         CHAR(64) NOT NULL,
    ocr_confidence      DECIMAL(5,2) NULL,
    ocr_text            LONGTEXT NULL,
    verdict             ENUM('accepted','rejected','manual_review') NOT NULL,
    flags_json          JSON NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at         DATETIME NULL,
    override_note       TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_gcash_reference UNIQUE (reference_number),
    CONSTRAINT uq_gcash_file_hash UNIQUE (sha256_hash),
    CONSTRAINT uq_gcash_payment_attempt UNIQUE (payment_id, attempt_no),
    CONSTRAINT fk_gcash_receipts_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_gcash_receipts_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_gcash_receipts_reviewer
        FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- Holds the valid ID (with affiliation verification), the POS, transaction
-- receipt, official receipt, refund supporting docs.
CREATE TABLE booking_documents (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id          BIGINT UNSIGNED NOT NULL,
    document_type       ENUM('customer_id','pos','transaction_receipt','official_receipt','refund_support','other') NOT NULL,
    file_path           VARCHAR(500) NOT NULL,
    original_filename   VARCHAR(255) NULL,
    stored_filename     VARCHAR(255) NULL,
    mime_type           VARCHAR(100) NULL,
    file_size_bytes     BIGINT UNSIGNED NULL,
    sha256_hash         CHAR(64) NULL,
    verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    uploaded_by_user_id BIGINT UNSIGNED NULL,
    uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_by_user_id BIGINT UNSIGNED NULL,               -- kept + to be added to UI (#13)
    verified_at         DATETIME NULL,
    notes               TEXT NULL,                          -- e.g. staff reason for rejecting an ID proof
    CONSTRAINT fk_booking_documents_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_booking_documents_uploader
        FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_booking_documents_verifier
        FOREIGN KEY (verified_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- Customer-requested refunds (decided 2026-09-09; switch added 2026-09-16).
-- Filing is allowed only when bookings.refunds_allowed = TRUE (the policy
-- snapshot) — the app checks it; the global switch never rewrites old bookings.
--   requested               filed, waiting on staff (customer may withdraw)
--   under_review            staff picked it up
--   returned_for_correction PAPERWORK problem, not a denial; customer fixes + resubmits
--   approved                staff said yes; paid out once the Official Receipt is in
--   rejected                denied — final; booking untouched
--   completed               money sent; ONLY now is the booking closed + date freed
--   withdrawn               customer pulled it before a decision; booking untouched
CREATE TABLE refunds (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id          BIGINT UNSIGNED NOT NULL,
    payment_id          BIGINT UNSIGNED NULL,
    refund_status       ENUM('requested','under_review','returned_for_correction','approved','rejected','completed','withdrawn') NOT NULL DEFAULT 'requested',
    -- the form's reason dropdown (reportable; keeps USeP-fault closures distinct)
    reason_category     ENUM('event_cancelled','schedule_conflict','wrong_room','venue_unavailable','payment_error','other') NOT NULL,
    reason              TEXT NOT NULL,                     -- the customer's free-text explanation
    amount_requested    DECIMAL(12,2) NOT NULL,            -- always the full amount paid; never typed by the customer
    amount_approved     DECIMAL(12,2) NULL,                -- set by staff
    refund_to_number    VARCHAR(30) NULL,                  -- GCash destination; NULL = cash booking (paid back at the counter)
    official_receipt_pending BOOLEAN NOT NULL DEFAULT FALSE, -- filed without the OR; blocks PAYOUT, never a decision
    correction_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- times returned for correction (capped in the app, like the 5-receipt rule)
    correction_due_at   DATETIME NULL,                     -- 48-hour resubmit window after a return
    payout_reference    VARCHAR(100) NULL,                 -- GCash reference of the refund sent (receipt image -> booking_documents)
    requested_by_user_id BIGINT UNSIGNED NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    requested_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resubmitted_at      DATETIME NULL,
    reviewed_at         DATETIME NULL,
    completed_at        DATETIME NULL,
    withdrawn_at        DATETIME NULL,
    notes               TEXT NULL,                         -- staff note the customer reads (why returned / denied)
    -- One OPEN request per booking. Finished (rejected/completed) and withdrawn
    -- requests drop to NULL here, so they stay as history without blocking.
    open_booking_id     BIGINT UNSIGNED GENERATED ALWAYS AS
                        (CASE WHEN refund_status IN ('requested','under_review','returned_for_correction','approved')
                              THEN booking_id ELSE NULL END) STORED,
    CONSTRAINT uq_refunds_one_open_per_booking UNIQUE (open_booking_id),
    CONSTRAINT chk_refund_requested CHECK (amount_requested > 0),
    CONSTRAINT chk_refund_approved CHECK (amount_approved IS NULL OR amount_approved >= 0),
    CONSTRAINT fk_refunds_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_refunds_requester
        FOREIGN KEY (requested_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_refunds_reviewer
        FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- Audit trail. Written by the APP on each status change (the auto-write trigger
-- is part of the deferred logic layer).
CREATE TABLE booking_timeline (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id              BIGINT UNSIGNED NOT NULL,
    action_code             VARCHAR(80) NOT NULL,
    old_reservation_status  VARCHAR(40) NULL,
    new_reservation_status  VARCHAR(40) NULL,
    old_payment_status      VARCHAR(40) NULL,
    new_payment_status      VARCHAR(40) NULL,
    performed_by_user_id    BIGINT UNSIGNED NULL,
    note                    TEXT NULL,
    metadata_json           JSON NULL,
    occurred_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_booking_timeline_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_booking_timeline_actor
        FOREIGN KEY (performed_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================================
-- 6b. ADMIN FAQs  (2026-09-18)
--     EVERY question on the customer FAQ page lives here — the built-in ones
--     (seeded below, is_builtin = TRUE, originals kept in default_*) and the
--     ones admins add. Live values are {placeholders} filled at render time;
--     policy says which refund-switch state a row is shown under.
--     Sections are a lookup so the admin page and the FAQ page agree on the
--     list; the FAQ page decides where each section renders.
-- ============================================================================
CREATE TABLE faq_sections (
    section_key         VARCHAR(40) PRIMARY KEY,
    label               VARCHAR(120) NOT NULL,
    sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE faqs (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_key         VARCHAR(40) NOT NULL,
    question            VARCHAR(255) NOT NULL,
    answer              TEXT NOT NULL,                             -- plain text + **bold**, [text](url), "- " lists, {placeholders}; no HTML
    sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,      -- within the section; new ones go last
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,             -- hidden entries stay editable, customers do not see them
    is_builtin          BOOLEAN NOT NULL DEFAULT FALSE,            -- shipped with the system: editable, hideable, never deletable
    policy              ENUM('any','refunds_on','refunds_off') NOT NULL DEFAULT 'any',   -- shown under which refund-switch state
    default_question    VARCHAR(255) NULL,                         -- built-ins only: the original wording, for "Restore original"
    default_answer      TEXT NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    updated_by_user_id  BIGINT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_faqs_section
        FOREIGN KEY (section_key) REFERENCES faq_sections(section_key)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_faqs_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_faqs_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE INDEX idx_faqs_section_order ON faqs (section_key, sort_order);

-- ============================================================================
-- 7. SEED DATA
-- ============================================================================

-- ---- Config (system_settings) ----
INSERT INTO system_settings (setting_key, setting_value, value_type, description) VALUES
  ('discount_percent',                '20', 'decimal', 'USeP-affiliated discount % (dynamic; staff-editable). Snapshotted onto each booking.'),
  ('hostel_pos_deadline_hours',       '72', 'integer', 'Hours an approved hostel booking may sit in await_pos before expiry checks release it.'),
  ('hostel_advance_booking_max_days', '7',  'integer', 'Hostel cannot be reserved more than this many days before check-in. Events: no limit.'),
  ('postpay_grace_days',              '3',  'integer', 'Refunds OFF only: days after the last booked day (event end / check-out) the customer has to pay. Past it payment_status becomes overdue; nothing is released.'),
  ('refunds_enabled',                 '0',  'boolean', 'Customer refund requests. 0 = OFF (all new bookings non-refundable, the USeP default). Admin-only. Snapshotted onto each booking as bookings.refunds_allowed.'),
  -- Showcase switch. A ROW, not a column, so removing demo mode later is one
  -- DELETE and leaves nothing behind in the schema. While it is on, booking /
  -- payment / refund writes go to the PHP session instead of the database, so a
  -- demo can be repeated forever and changes nothing. SETUP still writes for
  -- real (venues, rooms, amenities, rates, settings, FAQs, staff) — the split is
  -- transactions vs configuration. Admin-only, password-confirmed, and every
  -- page shows a banner while it is on.
  ('demo_mode',                       '0',  'boolean', 'Showcase mode: booking/payment/refund writes go to the session instead of the database. Setup still writes for real. Admin-only.');

-- ---- FAQ sections (keys = the section ids on customer/faq.php) ----
INSERT INTO faq_sections (section_key, label, sort_order) VALUES
  ('booking',  'Booking a venue',      1),
  ('discount', 'The USeP discount',    2),
  ('hostel',   'Hostel beds',          3),
  ('gcash',    'Paying with GCash',    4),
  ('cash',     'Paying in cash',       5),
  ('after',    'After you book',       6);

-- ---- Built-in FAQ questions (2026-09-18). Editable by admins; the originals are kept
--      in default_question / default_answer so "Restore original" always works.
--      {discount} {grace_days} {rate_communal} {rate_private} {cr_communal} {cr_private}
--      are filled in at render time from the live settings. policy = which refund
--      switch state the row is shown under. ----
INSERT INTO faqs (section_key, sort_order, is_builtin, policy, question, answer, default_question, default_answer) VALUES
  ('booking', 1, TRUE, 'any', 'How early do I need to book?', 'Reservations must start **at least 12 hours from the moment you book**, so venue staff have time to prepare. If you pick a date or time that is too soon, the booking page tells you the earliest start you can choose.', 'How early do I need to book?', 'Reservations must start **at least 12 hours from the moment you book**, so venue staff have time to prepare. If you pick a date or time that is too soon, the booking page tells you the earliest start you can choose.'),
  ('booking', 2, TRUE, 'any', 'Why does it say a date is "not available"?', 'That date already has a reservation for the room, and a booked date cannot be reserved again. Pick a different date — or, in a multi-day booking, keep your range: the booked date is left out automatically.', 'Why does it say a date is "not available"?', 'That date already has a reservation for the room, and a booked date cannot be reserved again. Pick a different date — or, in a multi-day booking, keep your range: the booked date is left out automatically.'),
  ('booking', 3, TRUE, 'any', 'Can I reserve several days at once?', 'Yes. Pick a start and end date, then set the hours for each day (or use "Apply Day 1 to all"). If a date in the middle of your range is already booked, it is excluded automatically — you keep the rest of the days and are **not charged** for the unavailable one.', 'Can I reserve several days at once?', 'Yes. Pick a start and end date, then set the hours for each day (or use "Apply Day 1 to all"). If a date in the middle of your range is already booked, it is excluded automatically — you keep the rest of the days and are **not charged** for the unavailable one.'),
  ('booking', 4, TRUE, 'any', 'How is the fee computed?', 'Each room has a fee per day. Your total is that fee times the number of days you actually book — excluded days are never counted. If you are USeP-affiliated, the {discount} rate is applied once staff have verified your USeP ID. The exact total is always shown before you pay.', 'How is the fee computed?', 'Each room has a fee per day. Your total is that fee times the number of days you actually book — excluded days are never counted. If you are USeP-affiliated, the {discount} rate is applied once staff have verified your USeP ID. The exact total is always shown before you pay.'),
  ('booking', 5, TRUE, 'any', 'What if my group is bigger than the room''s capacity?', 'You can still submit the request, but the page warns you, and venue staff may reject an over-capacity booking. Consider a larger room — every card on the venue page shows its capacity.', 'What if my group is bigger than the room''s capacity?', 'You can still submit the request, but the page warns you, and venue staff may reject an over-capacity booking. Consider a larger room — every card on the venue page shows its capacity.'),
  ('booking', 6, TRUE, 'any', 'Why do I need to upload a valid ID?', 'Staff verify who you are before approving any reservation. Attach a photo of your **USeP ID or any government-issued ID** when you submit. Your booking stays **pending** — and the payment step stays locked — until a coordinator approves both your ID and the reservation.', 'Why do I need to upload a valid ID?', 'Staff verify who you are before approving any reservation. Attach a photo of your **USeP ID or any government-issued ID** when you submit. Your booking stays **pending** — and the payment step stays locked — until a coordinator approves both your ID and the reservation.'),
  ('booking', 7, TRUE, 'refunds_on', 'When do I have to pay?', 'Payment opens only **after your request is approved**. You must pay at least **1 day before your event** — bookings made closer to the event than that are paid immediately upon approval. A reservation left unpaid past its deadline may be released back to availability.', 'When do I have to pay?', 'Payment opens only **after your request is approved**. You must pay at least **1 day before your event** — bookings made closer to the event than that are paid immediately upon approval. A reservation left unpaid past its deadline may be released back to availability.'),
  ('booking', 8, TRUE, 'refunds_off', 'When do I have to pay?', '**After your event, not before.** Staff approve your ID and reservation first; that holds your slot. Payment opens the day after your last booked day and is due within **{grace_days} days** — GCash or cash at the venue office. You cannot pay earlier. A booking not paid within the window is marked **overdue**; it can still be paid, but overdue accounts may not be able to book again until it is settled.', 'When do I have to pay?', '**After your event, not before.** Staff approve your ID and reservation first; that holds your slot. Payment opens the day after your last booked day and is due within **{grace_days} days** — GCash or cash at the venue office. You cannot pay earlier. A booking not paid within the window is marked **overdue**; it can still be paid, but overdue accounts may not be able to book again until it is settled.'),
  ('booking', 9, TRUE, 'refunds_off', 'Why do I pay after the event instead of before?', 'Because bookings are non-refundable. USeP does not hold your money for a venue it might still have to close — you pay once the event has actually taken place. If USeP has to close or cancel your venue before then, there is nothing to refund: you are simply offered a replacement room or a new date.', 'Why do I pay after the event instead of before?', 'Because bookings are non-refundable. USeP does not hold your money for a venue it might still have to close — you pay once the event has actually taken place. If USeP has to close or cancel your venue before then, there is nothing to refund: you are simply offered a replacement room or a new date.'),
  ('discount', 1, TRUE, 'any', 'Who gets the {discount} off?', 'USeP students, faculty and staff. It applies to venue bookings **and** hostel beds. When you book, choose **"USeP-affiliated"** and upload your **USeP ID** — staff confirm the discount from that ID when they approve your request.', 'Who gets the {discount} off?', 'USeP students, faculty and staff. It applies to venue bookings **and** hostel beds. When you book, choose **"USeP-affiliated"** and upload your **USeP ID** — staff confirm the discount from that ID when they approve your request.'),
  ('discount', 2, TRUE, 'any', 'I signed in with a usep.edu.ph email. Do I get it automatically?', 'Not by itself. A USeP email lets the venue page **preview** your discounted prices, but the discount is only **granted from your USeP ID** at approval. Upload the ID with your booking, even if your email is a USeP one.', 'I signed in with a usep.edu.ph email. Do I get it automatically?', 'Not by itself. A USeP email lets the venue page **preview** your discounted prices, but the discount is only **granted from your USeP ID** at approval. Upload the ID with your booking, even if your email is a USeP one.'),
  ('discount', 3, TRUE, 'any', 'Does the rate change?', 'The venue office sets the rate. Whatever rate is in force when your booking is approved is the one written onto that booking — a later change never alters a booking already made.', 'Does the rate change?', 'The venue office sets the rate. Whatever rate is in force when your booking is approved is the one written onto that booking — a later change never alters a booking already made.'),
  ('hostel', 1, TRUE, 'any', 'How is the hostel different from booking a venue?', 'You reserve **beds, not rooms**. Each room has six beds; you book one or more, and other guests may book the remaining beds in the same room. Book all six and the room is effectively yours. The price is **per head, per night**.', 'How is the hostel different from booking a venue?', 'You reserve **beds, not rooms**. Each room has six beds; you book one or more, and other guests may book the remaining beds in the same room. Book all six and the room is effectively yours. The price is **per head, per night**.'),
  ('hostel', 2, TRUE, 'any', 'Communal CR or private CR — what is the difference?', '**{cr_communal}** rooms share a bathroom outside the room — {rate_communal} per head, per night. **{cr_private}** rooms have the bathroom inside — {rate_private} per head, per night. The USeP discount applies to both.', 'Communal CR or private CR — what is the difference?', '**{cr_communal}** rooms share a bathroom outside the room — {rate_communal} per head, per night. **{cr_private}** rooms have the bathroom inside — {rate_private} per head, per night. The USeP discount applies to both.'),
  ('hostel', 3, TRUE, 'any', 'How are nights counted?', 'From check-in to check-out, **not including the check-out day**. Checking in on the 1st and out on the 4th is three nights — the 1st, 2nd and 3rd. The venue page shows how many beds are free for tonight; the booking page shows availability for your exact dates.', 'How are nights counted?', 'From check-in to check-out, **not including the check-out day**. Checking in on the 1st and out on the 4th is three nights — the 1st, 2nd and 3rd. The venue page shows how many beds are free for tonight; the booking page shows availability for your exact dates.'),
  ('hostel', 4, TRUE, 'refunds_on', 'How do I pay for a hostel bed?', 'Hostel payments go through **CEDU**. After staff approve your ID, they obtain a payment order from CEDU for your stay; you then pay — GCash to the business account, or cash to staff — and receive the **Official Receipt**. Your bed is held while this happens.', 'How do I pay for a hostel bed?', 'Hostel payments go through **CEDU**. After staff approve your ID, they obtain a payment order from CEDU for your stay; you then pay — GCash to the business account, or cash to staff — and receive the **Official Receipt**. Your bed is held while this happens.'),
  ('hostel', 5, TRUE, 'refunds_off', 'How do I pay for a hostel bed?', 'Hostel payments go through **CEDU**, and — like venues — you pay **after your stay**. Staff approve your ID and hold your beds. After you check out they obtain a payment order (POS) from CEDU; payment opens then and is due within **{grace_days} days of your check-out day** — GCash to the designated account, or cash to staff — and you receive the **Official Receipt**.', 'How do I pay for a hostel bed?', 'Hostel payments go through **CEDU**, and — like venues — you pay **after your stay**. Staff approve your ID and hold your beds. After you check out they obtain a payment order (POS) from CEDU; payment opens then and is due within **{grace_days} days of your check-out day** — GCash to the designated account, or cash to staff — and you receive the **Official Receipt**.'),
  ('gcash', 1, TRUE, 'any', 'How do I pay with GCash?', 'Once staff approve your ID and reservation, the payment step unlocks. Send the amount shown to the GCash account on screen, take a screenshot of your GCash receipt, and upload it. The system reads the receipt on the spot and tells you whether it was accepted.', 'How do I pay with GCash?', 'Once staff approve your ID and reservation, the payment step unlocks. Send the amount shown to the GCash account on screen, take a screenshot of your GCash receipt, and upload it. The system reads the receipt on the spot and tells you whether it was accepted.'),
  ('gcash', 2, TRUE, 'any', 'Why must I send the exact amount?', 'The automatic check compares your receipt against the **exact amount shown**. A different amount is not confirmed on the spot — it goes to a coordinator to look at instead, which delays your booking. A receipt is only rejected outright when **nothing** on it matches, or when the same receipt has already been used.', 'Why must I send the exact amount?', 'The automatic check compares your receipt against the **exact amount shown**. A different amount is not confirmed on the spot — it goes to a coordinator to look at instead, which delays your booking. A receipt is only rejected outright when **nothing** on it matches, or when the same receipt has already been used.'),
  ('gcash', 3, TRUE, 'any', 'What is the "Reference to include" (USEP-######)?', 'That is your **booking code** in this system. Type it into the message or note field when you send the GCash payment, so staff can match the payment to your booking. It is different from GCash''s own 13-digit reference number, which GCash prints on the receipt by itself.', 'What is the "Reference to include" (USEP-######)?', 'That is your **booking code** in this system. Type it into the message or note field when you send the GCash payment, so staff can match the payment to your booking. It is different from GCash''s own 13-digit reference number, which GCash prints on the receipt by itself.'),
  ('gcash', 4, TRUE, 'any', 'What does the system check on my receipt?', 'That the image is a real GCash send-money receipt; the GCash reference number; the **exact amount**; that the money went to the correct account; and the receipt date. It also rejects a receipt that was already submitted before — same image or same reference number.', 'What does the system check on my receipt?', 'That the image is a real GCash send-money receipt; the GCash reference number; the **exact amount**; that the money went to the correct account; and the receipt date. It also rejects a receipt that was already submitted before — same image or same reference number.'),
  ('gcash', 5, TRUE, 'any', 'My receipt says "a coordinator will check it". Is my booking lost?', 'No. Some detail could not be matched automatically — a blurry screenshot, an amount that differs, an older receipt — so a staff member verifies it by hand. Your slot is held while they do; you do not need to resubmit.', 'My receipt says "a coordinator will check it". Is my booking lost?', 'No. Some detail could not be matched automatically — a blurry screenshot, an amount that differs, an older receipt — so a staff member verifies it by hand. Your slot is held while they do; you do not need to resubmit.'),
  ('gcash', 6, TRUE, 'any', 'My receipt was rejected. What do I do?', 'Read the reasons listed under the result — usually money sent to a different account, or a receipt that was already used. Fix the issue (send the correct payment if needed), then upload a new screenshot with the reference number and amount clearly readable, **before your payment deadline**.', 'My receipt was rejected. What do I do?', 'Read the reasons listed under the result — usually money sent to a different account, or a receipt that was already used. Fix the issue (send the correct payment if needed), then upload a new screenshot with the reference number and amount clearly readable, **before your payment deadline**.'),
  ('cash', 1, TRUE, 'refunds_on', 'Can I pay in cash instead of GCash?', 'Yes. Choose **"Cash — walk-in"** at the payment step. Your reservation is submitted and held, and you pay in person at the **USeP Cashier — Venue Reservations Window** on campus (Monday to Friday, 8:00 AM – 5:00 PM). Quote your booking code and bring a valid ID. The booking is confirmed once the cashier records your payment — pay before your event date, or the slot may be released.', 'Can I pay in cash instead of GCash?', 'Yes. Choose **"Cash — walk-in"** at the payment step. Your reservation is submitted and held, and you pay in person at the **USeP Cashier — Venue Reservations Window** on campus (Monday to Friday, 8:00 AM – 5:00 PM). Quote your booking code and bring a valid ID. The booking is confirmed once the cashier records your payment — pay before your event date, or the slot may be released.'),
  ('cash', 2, TRUE, 'refunds_off', 'Can I pay in cash instead of GCash?', 'Yes. Choose **"Cash — walk-in"** at the payment step. Your reservation is submitted and held, and you pay in person at the **USeP Cashier — Venue Reservations Window** on campus (Monday to Friday, 8:00 AM – 5:00 PM). Quote your booking code and bring a valid ID. The booking is confirmed once the cashier records your payment. Under the post-pay policy you do this after the event, within the {grace_days}-day window.', 'Can I pay in cash instead of GCash?', 'Yes. Choose **"Cash — walk-in"** at the payment step. Your reservation is submitted and held, and you pay in person at the **USeP Cashier — Venue Reservations Window** on campus (Monday to Friday, 8:00 AM – 5:00 PM). Quote your booking code and bring a valid ID. The booking is confirmed once the cashier records your payment. Under the post-pay policy you do this after the event, within the {grace_days}-day window.'),
  ('after', 1, TRUE, 'any', 'What happens after I submit my reservation?', 'Two stages. First, staff review your **ID and reservation** — until they approve, your request is pending and payment is locked. Once approved, you pay (GCash or cash), and staff verify the payment itself — the GCash reference in the business account, or the cashier record — before the booking is finally confirmed. You can follow every step in [your booking history](booking-history.php).', 'What happens after I submit my reservation?', 'Two stages. First, staff review your **ID and reservation** — until they approve, your request is pending and payment is locked. Once approved, you pay (GCash or cash), and staff verify the payment itself — the GCash reference in the business account, or the cashier record — before the booking is finally confirmed. You can follow every step in [your booking history](booking-history.php).'),
  ('after', 2, TRUE, 'refunds_on', 'Who can ask for a refund?', 'A booking that is **approved**, **paid**, and whose date **has not been held yet**. Those bookings show a **Request refund** link in your booking history. Unpaid bookings and past events cannot be refunded.', 'Who can ask for a refund?', 'A booking that is **approved**, **paid**, and whose date **has not been held yet**. Those bookings show a **Request refund** link in your booking history. Unpaid bookings and past events cannot be refunded.'),
  ('after', 3, TRUE, 'refunds_on', 'What do I need to submit?', 'A reason, plus three documents: the **system transaction receipt**, your **proof of payment** (the GCash receipt, or the cashier receipt if you paid in cash), and the **Official Receipt**. You can file the request before the Official Receipt is ready — staff simply cannot pay the refund until they have it. If you paid by GCash, you also tell them which GCash number to send the refund to.', 'What do I need to submit?', 'A reason, plus three documents: the **system transaction receipt**, your **proof of payment** (the GCash receipt, or the cashier receipt if you paid in cash), and the **Official Receipt**. You can file the request before the Official Receipt is ready — staff simply cannot pay the refund until they have it. If you paid by GCash, you also tell them which GCash number to send the refund to.'),
  ('after', 4, TRUE, 'refunds_on', 'Does requesting a refund cancel my booking?', '**No.** The booking stays yours while staff review the request. You can **withdraw** the request at any point before they decide, and keep the booking. Your date is only released once the refund has actually been completed.', 'Does requesting a refund cancel my booking?', '**No.** The booking stays yours while staff review the request. You can **withdraw** the request at any point before they decide, and keep the booking. Your date is only released once the refund has actually been completed.'),
  ('after', 5, TRUE, 'refunds_on', 'What can staff decide?', 'One of three outcomes, each with a note you can read in your booking history:\n- **Refunded** — the money has been sent back; the proof (reference, amount, receipt) is attached to your booking.\n- **Needs a fix** — something is missing or unclear; correct it and resubmit.\n- **Denied** — with the reason. A denial is final, and the booking stays yours.', 'What can staff decide?', 'One of three outcomes, each with a note you can read in your booking history:\n- **Refunded** — the money has been sent back; the proof (reference, amount, receipt) is attached to your booking.\n- **Needs a fix** — something is missing or unclear; correct it and resubmit.\n- **Denied** — with the reason. A denial is final, and the booking stays yours.'),
  ('after', 6, TRUE, 'refunds_on', 'How is the money returned?', 'The same way you paid. A GCash payment is refunded **to GCash**, to the number you gave in the request. A cash payment is refunded **at the venue office**; staff will tell you when it is ready to collect.', 'How is the money returned?', 'The same way you paid. A GCash payment is refunded **to GCash**, to the number you gave in the request. A cash payment is refunded **at the venue office**; staff will tell you when it is ready to collect.'),
  ('after', 7, TRUE, 'any', 'What if USeP closes or cancels my venue?', 'If USeP has to close a room you have booked — for maintenance or any other reason — the venue office will offer you a **replacement room** or a **new date**. If you cannot accept either, your payment is returned in full. This does not depend on the refund policy.', 'What if USeP closes or cancels my venue?', 'If USeP has to close a room you have booked — for maintenance or any other reason — the venue office will offer you a **replacement room** or a **new date**. If you cannot accept either, your payment is returned in full. This does not depend on the refund policy.'),
  ('after', 8, TRUE, 'refunds_off', 'Can I get a refund?', '**No.** USeP does not give refunds: every booking is **final and non-refundable** once paid. Before you submit a booking you are asked to confirm that you understand this, so please check your date, room and details carefully before you pay.', 'Can I get a refund?', '**No.** USeP does not give refunds: every booking is **final and non-refundable** once paid. Before you submit a booking you are asked to confirm that you understand this, so please check your date, room and details carefully before you pay.'),
  ('after', 9, TRUE, 'refunds_off', 'I booked while refunds were still offered. Can I still ask for one?', '**Yes.** A booking keeps the policy it was made under. If yours was made while refunds were offered, it still shows a **Request refund** link in [your booking history](booking-history.php) while it is paid and the date has not been held yet — the request page lists the documents you need.', 'I booked while refunds were still offered. Can I still ask for one?', '**Yes.** A booking keeps the policy it was made under. If yours was made while refunds were offered, it still shows a **Request refund** link in [your booking history](booking-history.php) while it is paid and the date has not been held yet — the request page lists the documents you need.');

-- ---- Reservation statuses ----
INSERT INTO reservation_statuses (code, staff_label, customer_label, sort_order, is_terminal) VALUES
  ('pending',   'Reservation pending review',        'Pending',       1, 0),
  ('approved',  'Reservation approved',              'Approved',      2, 0),
  ('released',  'Slot released',                     'Released',      3, 0),
  ('rejected',  'Request rejected',                  'Rejected',      4, 1),
  ('cancelled', 'Cancelled',                         'Cancelled',     5, 1),
  ('completed', 'Completed',                         'Completed',     6, 1),
  ('disrupted', 'Room closed - awaiting decision',   'Action needed', 7, 0);

-- ---- Payment statuses ----
-- await_event (2026-09-17): the post-pay holding state. A booking made while
-- refunds were OFF is approved into it and stays there until its last day has
-- passed; sp_expire_due_bookings() then opens payment (await_gcash/await_cash).
INSERT INTO payment_statuses (code, staff_label, customer_label, sort_order) VALUES
  ('locked',            'Payment locked',           'Locked',            1),
  ('await_event',       'Payment due after event',  'Payment pending',   2),
  ('await_pos',         'Awaiting POS - CEDU',      'Awaiting POS',      3),
  ('await_gcash',       'Awaiting GCash payment',   'Payment due',       4),
  ('await_cash',        'Awaiting cash payment',    'Payment due',       5),
  ('under_review',      'Receipt under review',     'Under review',      6),
  ('confirmed',         'Payment confirmed',        'Paid',              7),
  ('paid_cash',         'Paid at cashier',          'Paid',              8),
  ('overdue',           'Payment overdue',          'Overdue',           9),
  ('expired',           'Expired',                  'Expired',           10),
  ('refund_requested',  'Refund requested',         'Refund requested',  11),
  ('refund_correction', 'Refund returned for correction', 'Refund - action needed', 12),
  ('refund_await_or',   'Refund approved - awaiting Official Receipt', 'Refund - Official Receipt needed', 13),
  ('refund_processing', 'Refund processing',        'Refund processing', 14),
  ('refunded',          'Refunded',                 'Refunded',          15),
  ('refund_denied',     'Refund denied',            'Refund denied',     16);

-- ---- Venues ----
INSERT INTO venues (id, venue_code, name, venue_type, description) VALUES
  (1, 'BAHAY-ALUMNI', 'Bahay Alumni', 'event',  'Heritage location for alumni events and functions.'),
  (2, 'USEP-VENUES',  'USeP Venues',  'event',  'Main campus location with halls and function rooms.'),
  (3, 'USEP-HOSTEL',  'USeP Hostel',  'hostel', 'Dormitory rooms booked per bed. Payment goes through CEDU.');

-- ---- GCash accounts (one active per venue). [SIM] USeP Venues + Hostel numbers are placeholders. ----
INSERT INTO gcash_accounts (venue_id, account_name, mobile_number, note) VALUES
  (1, 'Mika J Juarez',      '09951941234', 'Bahay Alumni business account.'),
  (2, 'Ramon T Villaflor',  '09183345566', 'USeP Venues business account. [SIM] placeholder number.'),
  (3, 'Rina S Delos Reyes', '09171234567', 'USeP Hostel designated staff account (cashed out to the University Cashier). [SIM] placeholder number.');

-- ---- Rooms (r1-r8 event; h1-h5 hostel). NOTE: amenities + at-a-glance are PHP-coded, not stored. ----
-- NOTE: description is the FULL customer-facing text and amenities are the
--       amenity KEYS (vocabulary: includes/amenities.php). Both are seeded
--       here so a fresh build matches what the rooms actually show; an
--       earlier seed carried abbreviated descriptions, which silently
--       shortened every room page the moment the catalog moved to the DB.
INSERT INTO rooms (id, venue_id, room_code, name, room_type, description, amenities) VALUES
  (1, 1, 'r1', 'Alumni Grand Ballroom', 'event', 'The flagship function hall of Bahay Alumni, ideal for graduation balls, conferences, and large university ceremonies. Column-free floor with a raised stage and full lighting rig.', '["stage_raised_podium","stage_lighting","sound_pro","aircon","chairs_300_stackable","led_wall"]'),
  (2, 1, 'r2', 'Heritage Function Room', 'event', 'A warm, mid-sized room for seminars, alumni homecomings, and department gatherings. Flexible seating layout with a built-in projector.', '["projector_ceiling","aircon","mic_handheld","chairs_tables_60","pantry"]'),
  (3, 1, 'r3', 'Alumni Boardroom', 'event', 'An executive boardroom for small committee meetings, thesis defenses, and interviews. Conference table seating for up to 20.', '["conference_table","tv_hdmi","aircon","whiteboard","coffee_station"]'),
  (4, 1, 'r4', 'Garden Pavilion', 'event', 'A semi-outdoor pavilion overlooking the alumni garden, popular for receptions and evening socials. Currently closed for roofing maintenance.', '["open_air_covered","lighting_string_spot","power_catering","seats_150"]'),
  (5, 2, 'r5', 'USeP Gymnasium', 'event', 'The main university gymnasium for large assemblies, intramurals, job fairs, and commencement exercises. Bleacher and floor seating combined.', '["seating_bleacher_floor","pa_full_court","stage_riser","gates_multiple","backstage"]'),
  (6, 2, 'r6', 'CIC Audio-Visual Room', 'event', 'A tiered audio-visual room in the College of Information & Computing, suited to colloquia, defenses, and film screenings.', '["seating_tiered","projector_4k","sound_surround","aircon","mic_wireless","wifi"]'),
  (7, 2, 'r7', 'Admin Conference Hall', 'event', 'A formal conference hall at the Administration building for council sessions, MOA signings, and official university meetings.', '["layout_ushape_theater","projector","aircon","podium_mics","vc_camera"]'),
  (8, 2, 'r8', 'Obrero Function Hall', 'event', 'A versatile function hall on the Obrero campus for orientations, trainings, and student org events. Open floor with modular staging.', '["stage_modular","projector","aircon","sound_basic","chairs_tables_200","load_in"]'),
  (9, 3, 'h1', 'Hostel Room 1', 'hostel', 'A six-bed room with bunk beds along both walls and a shared bathroom just down the hall. Each bed has its own locker and reading light.', '["bunk_beds_6","bath_shared_hall","aircon","locker_per_bed","reading_light_per_bed","lounge_access"]'),
  (10, 3, 'h2', 'Hostel Room 2', 'hostel', 'Six bunk beds with the shared bathroom directly opposite the door. The quietest of the communal rooms — it faces the inner courtyard.', '["bunk_beds_6","bath_shared_opposite","aircon","locker_per_bed","reading_light_per_bed","courtyard_quiet"]'),
  (11, 3, 'h3', 'Hostel Room 3', 'hostel', 'Six bunk beds with the shared bathroom down the hall. Ground floor, step-free access from the hostel entrance.', '["bunk_beds_6","bath_shared_hall","aircon","locker_per_bed","reading_light_per_bed","step_free_ground"]'),
  (12, 3, 'h4', 'Hostel Room 4', 'hostel', 'Six bunk beds with a private bathroom inside the room — no queueing down the hall. Hot shower and a wider walkway between bunks.', '["bunk_beds_6","bath_private","hot_shower","aircon","locker_per_bed","reading_light_per_bed"]'),
  (13, 3, 'h5', 'Hostel Room 5', 'hostel', 'Six bunk beds with a private bathroom and a small study table by the window. Second floor, overlooking the field.', '["bunk_beds_6","bath_private","hot_shower","aircon","study_table","locker_per_bed"]');

INSERT INTO event_room_details (room_id, attendee_capacity, fee_per_day) VALUES
  (1, 300, 5000.00), (2, 80, 2500.00), (3, 20, 1500.00), (4, 150, 3500.00),
  (5, 1000, 8000.00), (6, 120, 2000.00), (7, 60, 1800.00), (8, 200, 3000.00);

INSERT INTO hostel_room_details (room_id, cr_type, rate_per_head_per_night, check_in_time, check_out_time) VALUES
  (9,  'communal', 350.00, '14:00:00', '12:00:00'),
  (10, 'communal', 350.00, '14:00:00', '12:00:00'),
  (11, 'communal', 350.00, '14:00:00', '12:00:00'),
  (12, 'private',  400.00, '14:00:00', '12:00:00'),
  (13, 'private',  400.00, '14:00:00', '12:00:00');

-- ---- Hostel beds: 6 per hostel room (30 total) ----
INSERT INTO hostel_beds (room_id, bed_code, bed_label, display_order) VALUES
  (9,'h1-b1','Bed 1',1),(9,'h1-b2','Bed 2',2),(9,'h1-b3','Bed 3',3),(9,'h1-b4','Bed 4',4),(9,'h1-b5','Bed 5',5),(9,'h1-b6','Bed 6',6),
  (10,'h2-b1','Bed 1',1),(10,'h2-b2','Bed 2',2),(10,'h2-b3','Bed 3',3),(10,'h2-b4','Bed 4',4),(10,'h2-b5','Bed 5',5),(10,'h2-b6','Bed 6',6),
  (11,'h3-b1','Bed 1',1),(11,'h3-b2','Bed 2',2),(11,'h3-b3','Bed 3',3),(11,'h3-b4','Bed 4',4),(11,'h3-b5','Bed 5',5),(11,'h3-b6','Bed 6',6),
  (12,'h4-b1','Bed 1',1),(12,'h4-b2','Bed 2',2),(12,'h4-b3','Bed 3',3),(12,'h4-b4','Bed 4',4),(12,'h4-b5','Bed 5',5),(12,'h4-b6','Bed 6',6),
  (13,'h5-b1','Bed 1',1),(13,'h5-b2','Bed 2',2),(13,'h5-b3','Bed 3',3),(13,'h5-b4','Bed 4',4),(13,'h5-b5','Bed 5',5),(13,'h5-b6','Bed 6',6);

-- ---- Maintenance windows (match the mockup; system-wide, venue + hostel) ----
INSERT INTO maintenance_windows (room_id, from_date, until_date, reason, blocks_booking) VALUES
  (4,  '2026-07-01', NULL,         'Roof repair',                                TRUE),   -- HARD, indefinite
  (6,  '2026-07-10', '2026-07-31', 'One of two aircon units is being replaced',  FALSE),  -- MEDIUM
  (8,  '2026-08-03', '2026-08-07', 'Floor refinishing',                          TRUE),   -- HARD, planned
  (11, '2026-07-14', '2026-07-28', 'One of the two ceiling fans is being replaced', FALSE), -- MEDIUM (hostel)
  (13, '2026-08-10', '2026-08-16', 'Bathroom re-tiling',                         TRUE);   -- HARD (hostel)

-- ---- Room photos + 360 panoramas uploaded so far (2026-09-18). The image FILES live in
--      assets/img/venues/rooms/<room>/ (in git); these rows are what tie each file to its
--      room, so every copy of the database shows the same gallery. New uploads through
--      admin Venue Management add rows the same way. Order = filename order. ----
INSERT INTO room_media (room_id, media_type, file_path, original_filename, display_order, mime_type, file_size_bytes, sha256_hash) VALUES
  (1, 'photo', '../assets/img/venues/rooms/r1/photos/p_195724e23bd332b6.jpg', 'p_195724e23bd332b6.jpg', 1, 'image/jpeg', 272777, '851651e4c84d624403344822fb5a7589b7e78e9ab397c481eafc26ef255eb6fe'),
  (1, 'photo', '../assets/img/venues/rooms/r1/photos/p_5cc146ac1030f1ce.jpg', 'p_5cc146ac1030f1ce.jpg', 2, 'image/jpeg', 234238, 'a8ee840b907f9fbff07fddcbe8d93c4caf519a7f501db9960edb3f699eabd300'),
  (1, 'photo', '../assets/img/venues/rooms/r1/photos/p_66bc75d5a05605de.jpg', 'p_66bc75d5a05605de.jpg', 3, 'image/jpeg', 249547, 'dda62a9faaef6b6a5cb9fa62467cecb76d061c3e0bc145a9a51bbd01b6ee6d81'),
  (1, 'photo', '../assets/img/venues/rooms/r1/photos/p_c6bc1e7a6e4168ff.jpg', 'p_c6bc1e7a6e4168ff.jpg', 4, 'image/jpeg', 265273, '548738d9c25a183afa42dc5ee60cb61e66c6f30274224da0962b4f27311a77dc'),
  (1, 'photo', '../assets/img/venues/rooms/r1/photos/p_d8cbd39ff33257fb.jpg', 'p_d8cbd39ff33257fb.jpg', 5, 'image/jpeg', 247195, '8ba5b971570f876c0b9867e34ea7ee267a8426ea285e1b2c0ed108510fc74a77'),
  (1, 'panorama_360', '../assets/img/venues/rooms/r1/pano.jpg', 'pano.jpg', 1, 'image/jpeg', 208769, '6bcd595818f9d4fdd43b39a08a8eeb74ff901c41a3de37926a000d9a4680d1b3'),
  (2, 'photo', '../assets/img/venues/rooms/r2/photos/p_0f58bb13607ed456.jpg', 'p_0f58bb13607ed456.jpg', 1, 'image/jpeg', 316787, '6bcec5b33953b080faea75ed8c2254d3686a7a0decb8b35bdf10b76229db9c95'),
  (2, 'photo', '../assets/img/venues/rooms/r2/photos/p_1bc79a656c05b857.jpg', 'p_1bc79a656c05b857.jpg', 2, 'image/jpeg', 325065, '2fd1187c0436b57654a7b23b47c90c6ebfeeb80872c0c260b7d623cd4900dccc'),
  (2, 'photo', '../assets/img/venues/rooms/r2/photos/p_7350c5889584ccf8.jpg', 'p_7350c5889584ccf8.jpg', 3, 'image/jpeg', 327997, '185335bf7f4c47cf3407efdde8a53d5c6cb94d7814a3a0c587df514758757299'),
  (2, 'photo', '../assets/img/venues/rooms/r2/photos/p_8c111e5e53f43f7f.jpg', 'p_8c111e5e53f43f7f.jpg', 4, 'image/jpeg', 416909, 'b7217fe562feef33ca3a863b82ff2be6f0fadae116fefe1e0b08a63976cf06ce'),
  (2, 'photo', '../assets/img/venues/rooms/r2/photos/p_e2ff3aa8b50bf657.jpg', 'p_e2ff3aa8b50bf657.jpg', 5, 'image/jpeg', 286702, 'd769e7cf1b1c458fe9ea0fc96ba978f8d72a0d4b5768b25141f9412d86aef15d'),
  (2, 'panorama_360', '../assets/img/venues/rooms/r2/pano.jpg', 'pano.jpg', 1, 'image/jpeg', 60515, '28f4801bec240c92b760f78ce894bf25b6251be3b1b7cfc378b8549f776b2cda'),
  (5, 'photo', '../assets/img/venues/rooms/r5/photos/p_36cd7e532ad40cc2.jpg', 'p_36cd7e532ad40cc2.jpg', 1, 'image/jpeg', 371742, '9b0c0ee9ea666d680c8068f7817a743d1d2f998f5163437cdc9be078362ded72'),
  (5, 'photo', '../assets/img/venues/rooms/r5/photos/p_4ffd6ea76f8270a7.jpg', 'p_4ffd6ea76f8270a7.jpg', 2, 'image/jpeg', 402731, 'e7864021fa03547f245b64f7e46910d47e42d420b1b04f886b49754911c709bc'),
  (5, 'photo', '../assets/img/venues/rooms/r5/photos/p_64bac1882a79228a.jpg', 'p_64bac1882a79228a.jpg', 3, 'image/jpeg', 598889, 'c141e9222accff0af12e7dc0ce1653609e0c4f8d81d9079dfa39442497cda397'),
  (5, 'photo', '../assets/img/venues/rooms/r5/photos/p_aeab7ce563108458.jpg', 'p_aeab7ce563108458.jpg', 4, 'image/jpeg', 403973, '7fddfc0bd2bf9a4b79d731d5825163e15cce28d20625d82fbcb6f9eeb91cd2f6'),
  (5, 'photo', '../assets/img/venues/rooms/r5/photos/p_f0a6a0527e1a4c18.jpg', 'p_f0a6a0527e1a4c18.jpg', 5, 'image/jpeg', 398550, '3907955b252518eb38fd73798346bd8e976cb4912ca4ab769f9864f1bb0b96b5'),
  (5, 'panorama_360', '../assets/img/venues/rooms/r5/pano.jpg', 'pano.jpg', 1, 'image/jpeg', 559750, '978e9ae3e803dbca17e6f8856d9792126c96d400cd9c27bb9ce9cdd1c132a603'),
  (7, 'photo', '../assets/img/venues/rooms/r7/photos/p_74e3c2b7ed1a1d22.jpg', 'p_74e3c2b7ed1a1d22.jpg', 1, 'image/jpeg', 389605, '836e6208be0aca5c9f7d8561c6aa36bd69a866191ef716d123ae606830dc2d7e'),
  (7, 'photo', '../assets/img/venues/rooms/r7/photos/p_f1df6cece7195bac.jpg', 'p_f1df6cece7195bac.jpg', 2, 'image/jpeg', 537546, '1d0a18553fbd6f6fa886dcf63bd36d171598e588fc0884649ef0a324df57c1a1'),
  (9, 'photo', '../assets/img/venues/rooms/h1/photos/p_7b3b957a55ab7c7a.jpg', 'p_7b3b957a55ab7c7a.jpg', 1, 'image/jpeg', 203276, '2582a921055c056fb7e0875806da177518a05db9264669d81539aa77727c8c95'),
  (9, 'photo', '../assets/img/venues/rooms/h1/photos/p_b87804951d282be1.jpg', 'p_b87804951d282be1.jpg', 2, 'image/jpeg', 199283, '3111babc62497deb28e0d66e9712b3c1372ad2677cbc6bff5b6db0650a89396e'),
  (9, 'photo', '../assets/img/venues/rooms/h1/photos/p_d0ba12453f9cf539.jpg', 'p_d0ba12453f9cf539.jpg', 3, 'image/jpeg', 190072, 'caef4c1cdabfde9b8d9451a74ae6168a871c358ab5d1e24b348e3baef4168415'),
  (9, 'panorama_360', '../assets/img/venues/rooms/h1/pano.jpg', 'pano.jpg', 1, 'image/jpeg', 364740, '24fc7e783b3dff62b0484e29171c3fd014575b8aae7427f97e689e575db69154'),
  (10, 'photo', '../assets/img/venues/rooms/h2/photos/p_2ba8f0737f32daa4.jpg', 'p_2ba8f0737f32daa4.jpg', 1, 'image/jpeg', 180510, '2de8e9ecc818b6e5ed17a2e7e6d064072910d33c140d94be428868e39f3aec9a'),
  (10, 'photo', '../assets/img/venues/rooms/h2/photos/p_32db00ff82b43478.jpg', 'p_32db00ff82b43478.jpg', 2, 'image/jpeg', 180510, '2de8e9ecc818b6e5ed17a2e7e6d064072910d33c140d94be428868e39f3aec9a'),
  (10, 'photo', '../assets/img/venues/rooms/h2/photos/p_d46604c6349e0aec.jpg', 'p_d46604c6349e0aec.jpg', 3, 'image/jpeg', 199284, 'f60fed9af63fb80304ba83dfb11c29d25195b72588868092de238b165d7656bc'),
  (10, 'photo', '../assets/img/venues/rooms/h2/photos/p_d98cb22e5d1dbf65.jpg', 'p_d98cb22e5d1dbf65.jpg', 4, 'image/jpeg', 252148, '02aa430423a14a7eac1023d29873e962fb77ff8a84ee0fd4eadab6759a79f7ef'),
  (10, 'photo', '../assets/img/venues/rooms/h2/photos/p_db8b2c04c57b6720.jpg', 'p_db8b2c04c57b6720.jpg', 5, 'image/jpeg', 169411, '7641a85c6459db2c0acfd31dece2aa114b71a32703ce7cc2a4005dc621c82070'),
  (10, 'panorama_360', '../assets/img/venues/rooms/h2/pano.jpg', 'pano.jpg', 1, 'image/jpeg', 390822, 'd0ade09c598f2179ee0123c169206472aa440e2a0146a7829ef4206922005aeb'),
  (11, 'photo', '../assets/img/venues/rooms/h3/photos/p_12aa33737b93ca3e.jpg', 'p_12aa33737b93ca3e.jpg', 1, 'image/jpeg', 207487, 'ecd2a10aa0f2f54b3358db540a9c5f42ee79b6f712219f3c95a170de0c82eb47'),
  (11, 'photo', '../assets/img/venues/rooms/h3/photos/p_387496720e26df7c.jpg', 'p_387496720e26df7c.jpg', 2, 'image/jpeg', 149348, '527ed797f817fe22071a017f05f9a1b94609f68f0a731776d300eda0d6dace64'),
  (11, 'photo', '../assets/img/venues/rooms/h3/photos/p_9ee83973b14d98f2.jpg', 'p_9ee83973b14d98f2.jpg', 3, 'image/jpeg', 157577, '8d519d8ab2c2a9090c1c770e97343035dc18b40cbe5d589b1720cb9a95a0b3df'),
  (11, 'photo', '../assets/img/venues/rooms/h3/photos/p_a1a3f6b5c25d7b65.jpg', 'p_a1a3f6b5c25d7b65.jpg', 4, 'image/jpeg', 149348, '527ed797f817fe22071a017f05f9a1b94609f68f0a731776d300eda0d6dace64'),
  (11, 'photo', '../assets/img/venues/rooms/h3/photos/p_da28e82f07e66cb8.jpg', 'p_da28e82f07e66cb8.jpg', 5, 'image/jpeg', 158115, 'a648217fa7e77ab2cad2165da6c0af4d67605b18017f83fc61cee7beb2f237b3'),
  (11, 'panorama_360', '../assets/img/venues/rooms/h3/pano.jpg', 'pano.jpg', 1, 'image/jpeg', 61279, '54698a96114677646d79ebc2d4dd2dad3f942e78f376e0871cdb6b74a8db8896');

-- ---- Venue cover photos (assets/img/venues/covers/<venue id>.jpg) ----
UPDATE venues SET cover_photo = '../assets/img/venues/covers/1.jpg' WHERE id = 1;
UPDATE venues SET cover_photo = '../assets/img/venues/covers/2.jpg' WHERE id = 2;
UPDATE venues SET cover_photo = '../assets/img/venues/covers/3.jpg' WHERE id = 3;

-- ---- Test logins (carried over from the live DB, 2026-09-16). TEST PASSWORDS ONLY —
--      never reuse them for real data. There is no staff account yet (staff UI pending). ----
INSERT INTO users (id, email, username, password_hash, account_type) VALUES
  (1, 'admin@gmail.com',    'admin',    '$2y$10$s/z5l2Gsv5GqrXO35nZ4E.7n.6I0szBPD55RqHNBC3mc0CxHD1fDq', 'admin'),
  (2, 'customer@gmail.com', 'customer', '$2y$10$Eg3ofJRVPb0f0hSRxYflcObtnfjKhK5VZ3SMZC8HvFfc47WnWm0RC', 'customer');
INSERT INTO customers (id, user_id, full_name, phone, address, university_id_no) VALUES
  (1, 2, 'Brent Cajipoe', '09876543210', 'Molave, Zamboanga del Sur', '2024-00001');

-- ============================================================================
-- 8. INDEXES  (beyond the automatic FK/UNIQUE indexes)
-- ============================================================================
CREATE INDEX idx_rooms_venue_type        ON rooms (venue_id, room_type, is_active);
CREATE INDEX idx_bookings_queue          ON bookings (reservation_status, payment_status, submitted_at);
CREATE INDEX idx_bookings_customer_date  ON bookings (customer_id, submitted_at);
CREATE INDEX idx_bookings_deadline       ON bookings (current_deadline_at);
CREATE INDEX idx_venue_slots_conflict    ON venue_booking_slots (room_id, slot_date, released_at);
CREATE INDEX idx_bed_nights_avail        ON bed_reservation_nights (night_date, released_at);
CREATE INDEX idx_bed_nights_booking      ON bed_reservation_nights (booking_id, released_at);
CREATE INDEX idx_maint_room_dates        ON maintenance_windows (room_id, from_date, until_date, blocks_booking);
CREATE INDEX idx_hostel_beds_room_active ON hostel_beds (room_id, is_active, display_order);
CREATE INDEX idx_gcash_receipts_verdict  ON gcash_receipts (verdict, created_at);
CREATE INDEX idx_docs_booking_type       ON booking_documents (booking_id, document_type);
CREATE INDEX idx_payments_booking_status ON payments (booking_id, payment_record_status);
CREATE INDEX idx_timeline_booking_time   ON booking_timeline (booking_id, occurred_at);
CREATE INDEX idx_refunds_status          ON refunds (refund_status, requested_at);
CREATE INDEX idx_room_media_order        ON room_media (room_id, display_order);

-- ============================================================================
-- 9. FUNCTIONS  (read-only helpers)
-- ============================================================================
DELIMITER $$

-- Is a room HARD-blocked (blocks_booking=1) on a given date?
CREATE FUNCTION fn_room_hard_blocked(p_room_id BIGINT UNSIGNED, p_date DATE)
RETURNS BOOLEAN
NOT DETERMINISTIC READS SQL DATA
BEGIN
    RETURN EXISTS(
        SELECT 1 FROM maintenance_windows
        WHERE room_id = p_room_id
          AND blocks_booking = 1
          AND from_date <= p_date
          AND (until_date IS NULL OR until_date >= p_date)
    );
END$$

-- Free beds in a hostel room across a whole stay = the worst (minimum) night.
-- Counts only ACTIVE holds (not released, booking still pending/approved & unexpired).
CREATE FUNCTION fn_hostel_beds_free(p_room_id BIGINT UNSIGNED, p_check_in DATE, p_check_out DATE)
RETURNS INT
NOT DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE v_total INT DEFAULT 0;
    DECLARE v_min_free INT;
    DECLARE v_night DATE;
    DECLARE v_taken INT;

    SELECT COUNT(*) INTO v_total FROM hostel_beds WHERE room_id = p_room_id AND is_active = 1;
    SET v_min_free = v_total;
    SET v_night = p_check_in;

    WHILE v_night < p_check_out DO
        SELECT COUNT(*) INTO v_taken
        FROM bed_reservation_nights brn
        JOIN hostel_beds hb ON hb.id = brn.bed_id
        JOIN bookings b ON b.id = brn.booking_id
        WHERE hb.room_id = p_room_id
          AND brn.night_date = v_night
          AND brn.released_at IS NULL
          AND b.reservation_status IN ('pending','approved')
          AND (b.current_deadline_at IS NULL OR b.current_deadline_at > NOW());
        IF (v_total - v_taken) < v_min_free THEN SET v_min_free = v_total - v_taken; END IF;
        SET v_night = DATE_ADD(v_night, INTERVAL 1 DAY);
    END WHILE;

    RETURN v_min_free;
END$$

-- The current staff-editable discount % (from system_settings).
CREATE FUNCTION fn_current_discount_percent()
RETURNS DECIMAL(5,2)
NOT DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE v DECIMAL(5,2);
    SELECT CAST(setting_value AS DECIMAL(5,2)) INTO v
    FROM system_settings WHERE setting_key = 'discount_percent';
    RETURN COALESCE(v, 0.00);
END$$

-- First and last booked day of any booking (venue: start/end date; hostel:
-- check-in / check-out). Both policies' deadlines hang off these.
CREATE FUNCTION fn_booking_first_day(p_booking_id BIGINT UNSIGNED)
RETURNS DATE
NOT DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE v_day DATE;
    SELECT COALESCE(vbd.start_date, hbd.check_in_date) INTO v_day
      FROM bookings b
      LEFT JOIN venue_booking_details  vbd ON vbd.booking_id = b.id
      LEFT JOIN hostel_booking_details hbd ON hbd.booking_id = b.id
     WHERE b.id = p_booking_id;
    RETURN v_day;
END$$

CREATE FUNCTION fn_booking_last_day(p_booking_id BIGINT UNSIGNED)
RETURNS DATE
NOT DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE v_day DATE;
    SELECT COALESCE(vbd.end_date, hbd.check_out_date) INTO v_day
      FROM bookings b
      LEFT JOIN venue_booking_details  vbd ON vbd.booking_id = b.id
      LEFT JOIN hostel_booking_details hbd ON hbd.booking_id = b.id
     WHERE b.id = p_booking_id;
    RETURN v_day;
END$$

-- The pay-by moment for a booking, from ITS policy snapshot (2026-09-17):
--   pre-pay  (refunds_allowed = TRUE) : 23:59:59 the day before the first day;
--                                       if that is already past, the first
--                                       day's start time — pay before it begins.
--   post-pay (refunds_allowed = FALSE): 23:59:59 postpay_grace_days after the
--                                       last day.
CREATE FUNCTION fn_payment_deadline(p_booking_id BIGINT UNSIGNED)
RETURNS DATETIME
NOT DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE v_prepay BOOLEAN;
    DECLARE v_type VARCHAR(20);
    DECLARE v_first DATE;
    DECLARE v_last DATE;
    DECLARE v_start TIME DEFAULT '00:00:00';
    DECLARE v_grace INT DEFAULT 3;
    DECLARE v_deadline DATETIME;

    SELECT refunds_allowed INTO v_prepay FROM bookings WHERE id = p_booking_id;
    SET v_first = fn_booking_first_day(p_booking_id);
    SET v_last  = fn_booking_last_day(p_booking_id);
    IF v_first IS NULL THEN
        RETURN NULL;
    END IF;

    IF v_prepay THEN
        SET v_deadline = TIMESTAMP(DATE_SUB(v_first, INTERVAL 1 DAY), '23:59:59');
        IF v_deadline <= NOW() THEN
            SELECT booking_type INTO v_type FROM bookings WHERE id = p_booking_id;
            IF v_type = 'venue' THEN
                SELECT COALESCE(MIN(start_time), '00:00:00') INTO v_start
                  FROM venue_booking_slots WHERE booking_id = p_booking_id AND slot_date = v_first;
            ELSE
                SELECT COALESCE(hrd.check_in_time, '00:00:00') INTO v_start
                  FROM bookings b JOIN hostel_room_details hrd ON hrd.room_id = b.room_id
                 WHERE b.id = p_booking_id;
            END IF;
            SET v_deadline = TIMESTAMP(v_first, v_start);
        END IF;
    ELSE
        SELECT CAST(setting_value AS SIGNED) INTO v_grace
          FROM system_settings WHERE setting_key = 'postpay_grace_days';
        SET v_deadline = TIMESTAMP(DATE_ADD(v_last, INTERVAL v_grace DAY), '23:59:59');
    END IF;
    RETURN v_deadline;
END$$

DELIMITER ;

-- ============================================================================
-- 10. TRIGGERS  (integrity + audit + inventory release)
-- ============================================================================
DELIMITER $$

-- A room's type must match its venue's type.
CREATE TRIGGER trg_rooms_type_ins BEFORE INSERT ON rooms
FOR EACH ROW
BEGIN
    DECLARE v_kind VARCHAR(20);
    SELECT venue_type INTO v_kind FROM venues WHERE id = NEW.venue_id;
    IF v_kind IS NULL OR v_kind <> NEW.room_type THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Room type must match the venue type.';
    END IF;
END$$

CREATE TRIGGER trg_rooms_type_upd BEFORE UPDATE ON rooms
FOR EACH ROW
BEGIN
    DECLARE v_kind VARCHAR(20);
    SELECT venue_type INTO v_kind FROM venues WHERE id = NEW.venue_id;
    IF v_kind IS NULL OR v_kind <> NEW.room_type THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Room type must match the venue type.';
    END IF;
END$$

-- A booking's type must match its room's type.
CREATE TRIGGER trg_bookings_type_ins BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    DECLARE v_rt VARCHAR(20);
    SELECT room_type INTO v_rt FROM rooms WHERE id = NEW.room_id;
    -- room_type is 'event'/'hostel'; booking_type is 'venue'/'hostel'. A 'venue'
    -- booking belongs on an 'event' room; a 'hostel' booking on a 'hostel' room.
    IF v_rt IS NULL
       OR (NEW.booking_type = 'hostel' AND v_rt <> 'hostel')
       OR (NEW.booking_type = 'venue'  AND v_rt <> 'event') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Booking type must match the room type.';
    END IF;
END$$

CREATE TRIGGER trg_bookings_type_upd BEFORE UPDATE ON bookings
FOR EACH ROW
BEGIN
    DECLARE v_rt VARCHAR(20);
    SELECT room_type INTO v_rt FROM rooms WHERE id = NEW.room_id;
    -- room_type is 'event'/'hostel'; booking_type is 'venue'/'hostel'. A 'venue'
    -- booking belongs on an 'event' room; a 'hostel' booking on a 'hostel' room.
    IF v_rt IS NULL
       OR (NEW.booking_type = 'hostel' AND v_rt <> 'hostel')
       OR (NEW.booking_type = 'venue'  AND v_rt <> 'event') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Booking type must match the room type.';
    END IF;
END$$

-- On any status change: (1) write an audit row, (2) release held inventory when
-- the booking stops holding it. The app may set these before an UPDATE:
--   SET @venusep_actor_user_id = <user id>;  SET @venusep_action_note = '...';
CREATE TRIGGER trg_bookings_after_update AFTER UPDATE ON bookings
FOR EACH ROW
BEGIN
    IF OLD.reservation_status <> NEW.reservation_status
       OR OLD.payment_status <> NEW.payment_status THEN
        INSERT INTO booking_timeline (
            booking_id, action_code,
            old_reservation_status, new_reservation_status,
            old_payment_status, new_payment_status,
            performed_by_user_id, note
        ) VALUES (
            NEW.id, 'status_changed',
            OLD.reservation_status, NEW.reservation_status,
            OLD.payment_status, NEW.payment_status,
            COALESCE(@venusep_actor_user_id, NEW.updated_by_user_id),
            @venusep_action_note
        );
    END IF;

    IF NEW.reservation_status IN ('released','rejected','cancelled','completed','disrupted')
       AND OLD.reservation_status NOT IN ('released','rejected','cancelled','completed','disrupted') THEN
        UPDATE venue_booking_slots
            SET released_at = COALESCE(released_at, NOW())
            WHERE booking_id = NEW.id;
        UPDATE bed_reservation_nights
            SET released_at = COALESCE(released_at, NOW())
            WHERE booking_id = NEW.id;   -- active_bed_id recomputes to NULL, freeing the bed
    END IF;
END$$

-- An exact-bed assignment must be for a hostel booking, an ACTIVE bed in the
-- booking's room, and match the stay dates.
CREATE TRIGGER trg_bed_reservations_validate BEFORE INSERT ON bed_reservations
FOR EACH ROW
BEGIN
    DECLARE v_broom BIGINT UNSIGNED;
    DECLARE v_bedroom BIGINT UNSIGNED;
    DECLARE v_type VARCHAR(20);
    DECLARE v_ci DATE;
    DECLARE v_co DATE;
    DECLARE v_active BOOLEAN;

    SELECT b.room_id, b.booking_type, hbd.check_in_date, hbd.check_out_date
      INTO v_broom, v_type, v_ci, v_co
    FROM bookings b
    JOIN hostel_booking_details hbd ON hbd.booking_id = b.id
    WHERE b.id = NEW.booking_id;

    SELECT room_id, is_active INTO v_bedroom, v_active FROM hostel_beds WHERE id = NEW.bed_id;

    IF v_type <> 'hostel' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bed assignments are only for hostel bookings.';
    END IF;
    IF v_broom <> v_bedroom OR v_active = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The bed must be active and belong to the booking room.';
    END IF;
    IF NEW.check_in_date <> v_ci OR NEW.check_out_date <> v_co THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bed dates must match the hostel booking dates.';
    END IF;
END$$

DELIMITER ;

-- ============================================================================
-- 11. STORED PROCEDURES  (the inventory rules)
-- ============================================================================
DELIMITER $$

-- Deadline housekeeping. Call before availability/queue/detail reads.
--   (1) POST-PAY bookings whose last day has passed: open payment
--       (await_event -> await_gcash / await_cash). The customer chooses the
--       channel at checkout; GCash is the default until they pick cash.
--   (2) PRE-PAY bookings past their pay-by moment: release the hold (as before).
--   (3) POST-PAY bookings past their pay-by moment: mark OVERDUE. Nothing is
--       released — the event already happened; chasing it is staff's job.
CREATE PROCEDURE sp_expire_due_bookings ()
BEGIN
    SET @venusep_actor_user_id = NULL;

    SET @venusep_action_note = 'Event over; payment window opened.';
    UPDATE bookings
        SET payment_status = CASE WHEN payment_method = 'cash' THEN 'await_cash' ELSE 'await_gcash' END
        WHERE refunds_allowed = FALSE
          AND payment_status = 'await_event'
          AND reservation_status IN ('approved','completed')
          AND fn_booking_last_day(id) < CURDATE();

    SET @venusep_action_note = 'Auto-expired by deadline check.';
    UPDATE bookings
        SET reservation_status = 'released', payment_status = 'expired'
        WHERE refunds_allowed = TRUE
          AND reservation_status IN ('pending','approved')
          AND current_deadline_at IS NOT NULL
          AND current_deadline_at <= NOW();

    SET @venusep_action_note = 'Payment not received within the post-event window.';
    UPDATE bookings
        SET payment_status = 'overdue'
        WHERE refunds_allowed = FALSE
          AND reservation_status IN ('approved','completed')
          AND payment_status IN ('await_gcash','await_cash','await_pos')
          AND current_deadline_at IS NOT NULL
          AND current_deadline_at <= NOW();

    SET @venusep_action_note = NULL;
END$$

-- Staff approve a request. ONE entry point for both booking types and both
-- policies (2026-09-17): the status and the deadline follow the booking's
-- refunds_allowed snapshot, so no page has to know the rule.
--   venue  + pre-pay : approved / await_gcash|await_cash, pay-by = 1 day before
--   venue  + post-pay: approved / await_event,            pay-by = last day + grace
--   hostel + pre-pay : approved / await_pos (POS from CEDU first, as before)
--   hostel + post-pay: approved / await_event; staff call sp_start_await_pos
--                      after check-out, then the guest pays within the grace days.
CREATE PROCEDURE sp_approve_booking (
    IN p_booking_id BIGINT UNSIGNED,
    IN p_actor_user_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_type VARCHAR(20);
    DECLARE v_prepay BOOLEAN;
    DECLARE v_method VARCHAR(10);
    DECLARE v_status VARCHAR(40);

    SELECT booking_type, refunds_allowed, payment_method INTO v_type, v_prepay, v_method
      FROM bookings WHERE id = p_booking_id AND reservation_status = 'pending';
    IF v_type IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a pending booking can be approved.';
    END IF;

    IF v_type = 'hostel' AND v_prepay THEN
        CALL sp_start_await_pos(p_booking_id, p_actor_user_id);
    ELSE
        IF v_prepay THEN
            SET v_status = IF(v_method = 'cash', 'await_cash', 'await_gcash');
        ELSE
            SET v_status = 'await_event';
        END IF;
        SET @venusep_actor_user_id = p_actor_user_id;
        SET @venusep_action_note = IF(v_prepay, 'Approved; pay before the event.', 'Approved; pay after the event.');
        UPDATE bookings
            SET reservation_status = 'approved', payment_status = v_status,
                current_deadline_at = fn_payment_deadline(p_booking_id),
                approved_at = COALESCE(approved_at, NOW()),
                updated_by_user_id = p_actor_user_id
            WHERE id = p_booking_id;
        SET @venusep_actor_user_id = NULL;
        SET @venusep_action_note = NULL;
    END IF;
END$$

-- Put a hostel booking into await_pos (POS from CEDU before the guest can pay).
--   pre-pay : called at approval. Deadline = POS-deadline hours, capped at
--             check-in; past it the hold is released.
--   post-pay: called AFTER check-out (2026-09-17). Deadline = the booking's
--             pay-by moment (last day + grace days); past it the booking is
--             overdue, not released.
CREATE PROCEDURE sp_start_await_pos (
    IN p_booking_id BIGINT UNSIGNED,
    IN p_actor_user_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_hours INT DEFAULT 72;
    DECLARE v_ci DATE;
    DECLARE v_cit TIME;
    DECLARE v_type VARCHAR(20);
    DECLARE v_prepay BOOLEAN;
    DECLARE v_deadline DATETIME;

    SELECT CAST(setting_value AS UNSIGNED) INTO v_hours
        FROM system_settings WHERE setting_key = 'hostel_pos_deadline_hours';

    SELECT b.booking_type, b.refunds_allowed, hbd.check_in_date, COALESCE(hrd.check_in_time, '00:00:00')
      INTO v_type, v_prepay, v_ci, v_cit
    FROM bookings b
    JOIN hostel_booking_details hbd ON hbd.booking_id = b.id
    JOIN hostel_room_details hrd ON hrd.room_id = b.room_id
    WHERE b.id = p_booking_id;

    IF v_type <> 'hostel' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only hostel bookings can enter await_pos.';
    END IF;

    IF v_prepay THEN
        SET v_deadline = LEAST(DATE_ADD(NOW(), INTERVAL v_hours HOUR), TIMESTAMP(v_ci, v_cit));
    ELSE
        SET v_deadline = fn_payment_deadline(p_booking_id);
    END IF;

    SET @venusep_actor_user_id = p_actor_user_id;
    SET @venusep_action_note = IF(v_prepay, 'Approved; awaiting POS from CEDU.', 'Checked out; awaiting POS from CEDU.');
    UPDATE bookings
        SET reservation_status = 'approved', payment_status = 'await_pos',
            current_deadline_at = v_deadline,
            approved_at = COALESCE(approved_at, NOW()),
            updated_by_user_id = p_actor_user_id
        WHERE id = p_booking_id;
    SET @venusep_actor_user_id = NULL;
    SET @venusep_action_note = NULL;
END$$

-- Assign one occupant to one exact bed and create one claim per night. The
-- UNIQUE(active_bed_id, night_date) constraint makes the last-bed race safe.
CREATE PROCEDURE sp_assign_bed (
    IN p_booking_id BIGINT UNSIGNED,
    IN p_occupant_id BIGINT UNSIGNED,
    IN p_bed_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_ci DATE;
    DECLARE v_co DATE;
    DECLARE v_night DATE;
    DECLARE v_assignment BIGINT UNSIGNED;
    DECLARE v_block INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    CALL sp_expire_due_bookings();
    START TRANSACTION;

    SELECT check_in_date, check_out_date INTO v_ci, v_co
        FROM hostel_booking_details WHERE booking_id = p_booking_id FOR UPDATE;

    SELECT COUNT(*) INTO v_block
    FROM bookings b
    JOIN maintenance_windows mw ON mw.room_id = b.room_id
    WHERE b.id = p_booking_id
      AND mw.blocks_booking = 1
      AND mw.from_date < v_co
      AND COALESCE(mw.until_date, '9999-12-31') >= v_ci;
    IF v_block > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HARD maintenance falls during the requested stay.';
    END IF;

    INSERT INTO bed_reservations (booking_id, occupant_id, bed_id, check_in_date, check_out_date)
        VALUES (p_booking_id, p_occupant_id, p_bed_id, v_ci, v_co);
    SET v_assignment = LAST_INSERT_ID();

    SET v_night = v_ci;
    WHILE v_night < v_co DO
        INSERT INTO bed_reservation_nights (bed_reservation_id, booking_id, bed_id, night_date)
            VALUES (v_assignment, p_booking_id, p_bed_id, v_night);
        SET v_night = DATE_ADD(v_night, INTERVAL 1 DAY);
    END WHILE;

    COMMIT;
END$$

-- Add one venue day-slot with overlap + maintenance protection. A named lock
-- serializes claims for the same room+date so two requests can't both pass.
CREATE PROCEDURE sp_add_venue_slot (
    IN p_booking_id BIGINT UNSIGNED,
    IN p_slot_date DATE,
    IN p_start_time TIME,
    IN p_end_time TIME
)
BEGIN
    DECLARE v_room_id BIGINT UNSIGNED;
    DECLARE v_type VARCHAR(20);
    DECLARE v_conflict INT DEFAULT 0;
    DECLARE v_block INT DEFAULT 0;
    DECLARE v_lock VARCHAR(128);
    DECLARE v_got INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF v_got = 1 THEN DO RELEASE_LOCK(v_lock); END IF;
        RESIGNAL;
    END;

    CALL sp_expire_due_bookings();

    IF p_end_time <= p_start_time THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Slot end time must be later than start time.';
    END IF;

    SELECT room_id, booking_type INTO v_room_id, v_type FROM bookings WHERE id = p_booking_id;
    IF v_type <> 'venue' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Venue slots are only for venue bookings.';
    END IF;

    SET v_lock = CONCAT('venusep:venue:', v_room_id, ':', DATE_FORMAT(p_slot_date, '%Y%m%d'));
    SELECT GET_LOCK(v_lock, 10) INTO v_got;
    IF v_got <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Could not obtain a room-date availability lock.';
    END IF;

    SELECT COUNT(*) INTO v_block
    FROM maintenance_windows
    WHERE room_id = v_room_id
      AND blocks_booking = 1
      AND from_date <= p_slot_date
      AND COALESCE(until_date, '9999-12-31') >= p_slot_date;
    IF v_block > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HARD maintenance on the requested date.';
    END IF;

    SELECT COUNT(*) INTO v_conflict
    FROM venue_booking_slots s
    JOIN bookings b ON b.id = s.booking_id
    WHERE s.room_id = v_room_id
      AND s.slot_date = p_slot_date
      AND s.released_at IS NULL
      AND b.reservation_status IN ('pending','approved')
      AND (b.current_deadline_at IS NULL OR b.current_deadline_at > NOW())
      AND p_start_time < s.end_time
      AND p_end_time > s.start_time;
    IF v_conflict > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The room is already held for an overlapping time range.';
    END IF;

    INSERT INTO venue_booking_slots (booking_id, room_id, slot_date, start_time, end_time)
        VALUES (p_booking_id, v_room_id, p_slot_date, p_start_time, p_end_time);

    DO RELEASE_LOCK(v_lock);
END$$

DELIMITER ;

-- ============================================================================
-- 12. VIEWS
-- ============================================================================

-- Every booking with its labels + a display reference (VNS-YYYY-######).
CREATE OR REPLACE VIEW v_booking_summary AS
SELECT
    b.id AS booking_id,
    CONCAT('VNS-', YEAR(b.submitted_at), '-', LPAD(b.id, 6, '0')) AS booking_reference,
    b.customer_id, c.full_name AS customer_name,
    b.room_id, r.name AS room_name, v.id AS venue_id, v.name AS venue_name,
    b.booking_type,
    b.reservation_status, rs.staff_label AS reservation_staff_label, rs.customer_label AS reservation_customer_label,
    b.payment_status, ps.staff_label AS payment_staff_label, ps.customer_label AS payment_customer_label,
    b.payment_method, b.is_usep_affiliated, b.affiliation_verified,
    b.room_price, b.discount_percent, b.discount_amount, b.total_amount,
    b.refunds_allowed,
    IF(b.refunds_allowed, 'prepay', 'postpay') AS payment_policy,   -- 2026-09-17: when this booking pays
    fn_booking_first_day(b.id) AS first_day,
    fn_booking_last_day(b.id)  AS last_day,
    b.current_deadline_at, b.submitted_at, b.updated_at
FROM bookings b
JOIN customers c ON c.id = b.customer_id
JOIN rooms r ON r.id = b.room_id
JOIN venues v ON v.id = r.venue_id
JOIN reservation_statuses rs ON rs.code = b.reservation_status
JOIN payment_statuses ps ON ps.code = b.payment_status;

-- Venue slots still holding inventory (not released, booking live).
CREATE OR REPLACE VIEW v_active_venue_slots AS
SELECT s.id AS venue_slot_id, s.booking_id, s.room_id, s.slot_date, s.start_time, s.end_time
FROM venue_booking_slots s
JOIN bookings b ON b.id = s.booking_id
WHERE s.released_at IS NULL
  AND b.reservation_status IN ('pending','approved')
  AND (b.current_deadline_at IS NULL OR b.current_deadline_at > NOW());

-- Hostel bed-nights still holding inventory.
CREATE OR REPLACE VIEW v_active_hostel_bed_nights AS
SELECT brn.bed_id, hb.room_id, brn.night_date, brn.booking_id, brn.bed_reservation_id
FROM bed_reservation_nights brn
JOIN hostel_beds hb ON hb.id = brn.bed_id
JOIN bookings b ON b.id = brn.booking_id
WHERE brn.released_at IS NULL
  AND b.reservation_status IN ('pending','approved')
  AND (b.current_deadline_at IS NULL OR b.current_deadline_at > NOW());

-- The one active GCash account per venue (what the payment screen + checker use).
CREATE OR REPLACE VIEW v_gcash_active_accounts AS
SELECT ga.venue_id, v.name AS venue_name, ga.account_name, ga.mobile_number
FROM gcash_accounts ga
JOIN venues v ON v.id = ga.venue_id
WHERE ga.active_venue_id IS NOT NULL;

-- Maintenance windows in effect today, per room (hard vs medium).
CREATE OR REPLACE VIEW v_room_current_maintenance AS
SELECT mw.room_id, r.name AS room_name, mw.from_date, mw.until_date, mw.reason,
       CASE WHEN mw.blocks_booking THEN 'hard' ELSE 'medium' END AS severity
FROM maintenance_windows mw
JOIN rooms r ON r.id = mw.room_id
WHERE mw.from_date <= CURDATE()
  AND (mw.until_date IS NULL OR mw.until_date >= CURDATE());

-- ============================================================================
-- 13. APPLICATION NOTES
-- ============================================================================
-- 1) Call sp_expire_due_bookings() before availability / queue / detail reads
--    (it also runs inside sp_assign_bed and sp_add_venue_slot).
-- 2) Use sp_add_venue_slot() once per inclusive venue day (it holds a per
--    room+date lock and rejects overlaps + HARD maintenance).
-- 3) Use sp_assign_bed() for each occupant->bed (race-safe; rejects HARD
--    maintenance over the stay).
-- 4) Use sp_approve_booking() when staff approve ANY request — it picks the
--    status + deadline for the booking's policy. Under post-pay, call
--    sp_start_await_pos() for a hostel booking after the guest checks out.
-- 5) Before a direct status UPDATE, set @venusep_actor_user_id and
--    @venusep_action_note so the audit trigger records who + why.
-- 6) Availability helpers: fn_room_hard_blocked(room,date),
--    fn_hostel_beds_free(room,check_in,check_out), fn_current_discount_percent().
-- 7) Never hard-delete bookings — change statuses (history + audit rely on it).
-- 8) REFUND SWITCH: when INSERTing a booking, copy system_settings.refunds_enabled
--    into bookings.refunds_allowed. A customer may file a refund only when THAT
--    booking's refunds_allowed = TRUE (plus the usual eligibility). Changing the
--    switch writes a system_settings_history row and is admin-only, behind
--    password re-entry (users.reauth_* columns hold the lockout).
-- 10) ADMIN FAQs (2026-09-18): faqs + faq_sections. The customer FAQ page
--    renders every ACTIVE row for the current refund policy (built-ins are
--    seeded rows; {placeholders} filled live). admin/faq-management.php is the
--    only writer (admin + staff; CSRF-checked in admin/faq-save.php).
-- 9) PAYMENT TIMING (2026-09-17) follows the same snapshot. refunds_allowed
--    TRUE = pre-pay (pay after approval, 1 day before the event; unpaid holds
--    are released). FALSE = post-pay (payment opens after the last day and is
--    due within postpay_grace_days; unpaid bookings go OVERDUE, never
--    released). fn_payment_deadline() is the only place the dates are
--    computed; sp_expire_due_bookings() opens post-pay windows and marks
--    overdue. An overdue booking can still be paid — it simply turns Paid late.
-- ============================================================================
