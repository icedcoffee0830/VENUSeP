-- ============================================================================
-- VENUSeP Database Schema
-- Target: MySQL 8.0+ / MariaDB 10.4+ (XAMPP)
-- Built to match DB-DECISIONS.md (locked 2026-07-19). Where this and the
-- first-draft schema differ, DB-DECISIONS.md is the authority.
--
-- SCOPE OF THIS FILE: tables + constraints + seed data ("tables first").
-- The logic layer (stored procedures, most triggers, tuned indexes, views)
-- is intentionally DEFERRED — see the notes at the very bottom.
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
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_users_email UNIQUE (email),
    CONSTRAINT uq_users_username UNIQUE (username)
) ENGINE=InnoDB;

ALTER TABLE system_settings
    ADD CONSTRAINT fk_settings_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL;

CREATE TABLE customers (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             BIGINT UNSIGNED NULL,          -- NULL = walk-in (no login)
    full_name           VARCHAR(190) NOT NULL,
    phone               VARCHAR(30) NULL,
    address             VARCHAR(500) NULL,
    university_id_no    VARCHAR(80) NULL,
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
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,      -- FALSE = retire/hide a room (distinct from a maintenance window)
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
    current_deadline_at DATETIME NULL,
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

CREATE TABLE refunds (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id          BIGINT UNSIGNED NOT NULL,
    payment_id          BIGINT UNSIGNED NULL,
    refund_status       ENUM('requested','under_review','approved','rejected','completed') NOT NULL DEFAULT 'requested',
    reason              TEXT NOT NULL,
    amount_requested    DECIMAL(12,2) NOT NULL,
    amount_approved     DECIMAL(12,2) NULL,
    requested_by_user_id BIGINT UNSIGNED NULL,
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    requested_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at         DATETIME NULL,
    completed_at        DATETIME NULL,
    notes               TEXT NULL,
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
-- 7. SEED DATA
-- ============================================================================

-- ---- Config (system_settings) ----
INSERT INTO system_settings (setting_key, setting_value, value_type, description) VALUES
  ('discount_percent',                '20', 'decimal', 'USeP-affiliated discount % (dynamic; staff-editable). Snapshotted onto each booking.'),
  ('hostel_pos_deadline_hours',       '72', 'integer', 'Hours an approved hostel booking may sit in await_pos before expiry checks release it.'),
  ('hostel_advance_booking_max_days', '7',  'integer', 'Hostel cannot be reserved more than this many days before check-in. Events: no limit.');

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
INSERT INTO payment_statuses (code, staff_label, customer_label, sort_order) VALUES
  ('locked',            'Payment locked',           'Locked',            1),
  ('await_pos',         'Awaiting POS - CEDU',      'Awaiting POS',      2),
  ('await_gcash',       'Awaiting GCash payment',   'Awaiting payment',  3),
  ('await_cash',        'Awaiting cash payment',    'Awaiting payment',  4),
  ('under_review',      'Receipt under review',     'Under review',      5),
  ('confirmed',         'Payment confirmed',        'Paid',              6),
  ('paid_cash',         'Paid at cashier',          'Paid',              7),
  ('overdue',           'Payment overdue',          'Overdue',           8),
  ('expired',           'Expired',                  'Expired',           9),
  ('refund_requested',  'Refund requested',         'Refund requested',  10),
  ('refund_processing', 'Refund processing',        'Refund processing', 11),
  ('refunded',          'Refunded',                 'Refunded',          12);

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
INSERT INTO rooms (id, venue_id, room_code, name, room_type, description) VALUES
  (1, 1, 'r1', 'Alumni Grand Ballroom', 'event', 'Flagship function hall for balls, conferences, and large ceremonies.'),
  (2, 1, 'r2', 'Heritage Function Room', 'event', 'Mid-sized room for seminars, homecomings, and department gatherings.'),
  (3, 1, 'r3', 'Alumni Boardroom',       'event', 'Executive boardroom for meetings, thesis defenses, and interviews.'),
  (4, 1, 'r4', 'Garden Pavilion',        'event', 'Semi-outdoor pavilion for receptions and evening socials.'),
  (5, 2, 'r5', 'USeP Gymnasium',         'event', 'Main gymnasium for assemblies, intramurals, and job fairs.'),
  (6, 2, 'r6', 'CIC Audio-Visual Room',  'event', 'Tiered AV room for colloquia, defenses, and film screenings.'),
  (7, 2, 'r7', 'Admin Conference Hall',  'event', 'Formal conference hall for council sessions and MOA signings.'),
  (8, 2, 'r8', 'Obrero Function Hall',   'event', 'Versatile function hall for orientations, trainings, and org events.'),
  (9,  3, 'h1', 'Hostel Room 1', 'hostel', 'Six-bed room, shared bathroom down the hall.'),
  (10, 3, 'h2', 'Hostel Room 2', 'hostel', 'Six-bed room, shared bathroom, courtyard-facing (quiet).'),
  (11, 3, 'h3', 'Hostel Room 3', 'hostel', 'Six-bed room, shared bathroom, ground floor step-free access.'),
  (12, 3, 'h4', 'Hostel Room 4', 'hostel', 'Six-bed room with a private bathroom in-room.'),
  (13, 3, 'h5', 'Hostel Room 5', 'hostel', 'Six-bed room with a private bathroom and a study table.');

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

-- ---- One demo login + customer (the mockup's session user). [SIM] replace password_hash. ----
INSERT INTO users (id, email, username, password_hash, account_type) VALUES
  (1, 'jmdelacruz@usep.edu.ph', 'jmdelacruz', '$2y$10$REPLACE_WITH_A_REAL_BCRYPT_HASH_0000000000000000000000', 'customer');
INSERT INTO customers (id, user_id, full_name, phone) VALUES
  (1, 1, 'Juan Miguel Dela Cruz', '0917 555 0123');

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

-- Release holds whose deadline has passed. Call before availability/queue reads.
CREATE PROCEDURE sp_expire_due_bookings ()
BEGIN
    SET @venusep_actor_user_id = NULL;
    SET @venusep_action_note = 'Auto-expired by deadline check.';
    UPDATE bookings
        SET reservation_status = 'released', payment_status = 'expired'
        WHERE reservation_status IN ('pending','approved')
          AND current_deadline_at IS NOT NULL
          AND current_deadline_at <= NOW();
    SET @venusep_action_note = NULL;
END$$

-- Approve a hostel booking into await_pos with a deadline (POS-deadline hours,
-- capped at check-in). Payment stays locked until the POS is recorded.
CREATE PROCEDURE sp_start_await_pos (
    IN p_booking_id BIGINT UNSIGNED,
    IN p_actor_user_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_hours INT DEFAULT 72;
    DECLARE v_ci DATE;
    DECLARE v_cit TIME;
    DECLARE v_type VARCHAR(20);
    DECLARE v_deadline DATETIME;

    SELECT CAST(setting_value AS UNSIGNED) INTO v_hours
        FROM system_settings WHERE setting_key = 'hostel_pos_deadline_hours';

    SELECT b.booking_type, hbd.check_in_date, COALESCE(hrd.check_in_time, '00:00:00')
      INTO v_type, v_ci, v_cit
    FROM bookings b
    JOIN hostel_booking_details hbd ON hbd.booking_id = b.id
    JOIN hostel_room_details hrd ON hrd.room_id = b.room_id
    WHERE b.id = p_booking_id;

    IF v_type <> 'hostel' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only hostel bookings can enter await_pos.';
    END IF;

    SET v_deadline = LEAST(DATE_ADD(NOW(), INTERVAL v_hours HOUR), TIMESTAMP(v_ci, v_cit));

    SET @venusep_actor_user_id = p_actor_user_id;
    SET @venusep_action_note = 'Approved; awaiting POS from CEDU.';
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
-- 4) Use sp_start_await_pos() when staff approve a hostel request.
-- 5) Before a direct status UPDATE, set @venusep_actor_user_id and
--    @venusep_action_note so the audit trigger records who + why.
-- 6) Availability helpers: fn_room_hard_blocked(room,date),
--    fn_hostel_beds_free(room,check_in,check_out), fn_current_discount_percent().
-- 7) Never hard-delete bookings — change statuses (history + audit rely on it).
-- ============================================================================
