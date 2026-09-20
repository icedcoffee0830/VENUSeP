-- ============================================================================
--  VENUSeP — DEMO SEED
--
--      mysql -u root venusep < venusep_demo_seed.sql     (installs the procs)
--      CALL sp_seed_demo();                              (builds the showcase)
--
--  ⚠️ sp_seed_demo() is a RESET. It DELETES every booking, payment, receipt,
--     refund and document and rebuilds them. It does NOT touch the catalog
--     (venues, rooms, rates, photos, FAQs) and it does NOT delete the two
--     original accounts. Never run it on a database holding real bookings.
--
--  WHY DATES ARE RELATIVE
--  ----------------------
--  Every date is computed from CURDATE(), never written as a literal. The old
--  hard-coded data proved why: its maintenance windows and bookings were
--  written for July 2026 and had all silently expired, so "medium
--  maintenance", "a planned closure" and "a date that is already taken" had
--  quietly become impossible to demonstrate — with nothing to announce it.
--  Re-run this proc any time and the showcase is current again.
--
--  WHY IT GOES THROUGH THE REAL RULES
--  ----------------------------------
--  The seed calls fn_payment_deadline() for every pay-by date and
--  fn_room_hard_blocked() to decide which days a multi-day booking may hold.
--  Seed data that computed its own answers could disagree with the running
--  system, and a demo built on data the app would never have produced proves
--  nothing. Bed assignments go through the real UNIQUE(bed, night) guard too.
--
--  THE CAST — every customer password is customer123.
--    admin@gmail.com        admin      (kept, admin123)
--    customer@gmail.com     Brent Cajipoe        non-USeP        (kept)
--    jmdelacruz@usep.edu.ph Juan Miguel Dela Cruz USeP, verified
--    msantos@usep.edu.ph    Maria Santos          USeP, NOT verified
--    rafael.lim@gmail.com   Rafael Lim            non-USeP, org
--    areyes@usep.edu.ph     Ana Reyes             USeP, hostel guest
--    (no login)             Carmen Uy             walk-in
--    staff@gmail.com        Marites Robles        staff (staff123, no UI yet)
-- ============================================================================

DROP PROCEDURE IF EXISTS sp_seed_demo;
DROP PROCEDURE IF EXISTS sp_seed_reset;
DROP PROCEDURE IF EXISTS sp_seed_cast;
DROP PROCEDURE IF EXISTS sp_seed_maintenance;
DROP PROCEDURE IF EXISTS sp_seed_booking_venue;
DROP PROCEDURE IF EXISTS sp_seed_booking_hostel;
DROP PROCEDURE IF EXISTS sp_seed_add_occupant;
DROP PROCEDURE IF EXISTS sp_seed_payment;
DROP FUNCTION  IF EXISTS fn_seed_customer;

DELIMITER $$

-- ---------------------------------------------------------------------------
-- fn_seed_customer — a customer id from a login email, or from a name for the
-- walk-in (who has no login at all: customers.user_id IS NULL, DB-DECISIONS #4)
-- ---------------------------------------------------------------------------
CREATE FUNCTION fn_seed_customer (p_key VARCHAR(190))
RETURNS BIGINT UNSIGNED
READS SQL DATA
BEGIN
    DECLARE v_id BIGINT UNSIGNED;
    IF p_key LIKE '%@%' THEN
        SELECT c.id INTO v_id
          FROM customers c JOIN users u ON u.id = c.user_id
         WHERE u.email = p_key LIMIT 1;
    ELSE
        SELECT id INTO v_id FROM customers
         WHERE full_name = p_key AND user_id IS NULL LIMIT 1;
    END IF;
    RETURN v_id;
END$$

-- ---------------------------------------------------------------------------
-- sp_seed_reset — wipe the showcase, child rows first so no FK is ever violated
-- ---------------------------------------------------------------------------
CREATE PROCEDURE sp_seed_reset ()
BEGIN
    DELETE FROM booking_timeline;
    DELETE FROM refunds;
    DELETE FROM gcash_receipts;
    DELETE FROM payments;
    DELETE FROM booking_documents;
    DELETE FROM bed_reservation_nights;
    DELETE FROM bed_reservations;
    DELETE FROM hostel_occupants;
    DELETE FROM hostel_booking_details;
    DELETE FROM venue_booking_slots;
    DELETE FROM venue_booking_details;
    DELETE FROM bookings;
    DELETE FROM maintenance_windows;

    -- ACCOUNTS ARE DELIBERATELY NOT DELETED.
    --
    -- This proc used to drop and recreate the demo cast, which gave every one
    -- of them a NEW customers.id on each run. Anyone signed in at the time kept
    -- the OLD id in their session, so their pages still rendered and then every
    -- booking died on a foreign key — a logged-in customer who could not book,
    -- with nothing on screen explaining why. Resetting the showcase mid-demo is
    -- exactly when that would happen.
    --
    -- So the reset owns TRANSACTIONS only. sp_seed_cast() below brings the cast
    -- up to date in place, which keeps their ids, their sessions and anything
    -- they registered during the demo intact.
END$$

-- ---------------------------------------------------------------------------
-- sp_seed_cast — the people. Hashes are real bcrypt for the passwords above.
-- ---------------------------------------------------------------------------
CREATE PROCEDURE sp_seed_cast ()
BEGIN
    -- UPSERT, never delete-and-recreate: the cast keeps its ids across every
    -- reset, so open sessions stay valid and the bookings seeded below always
    -- attach to the same people. ON DUPLICATE KEY also repairs an account
    -- somebody changed during a demo — the password goes back to the documented
    -- one, which is the whole point of a reset.
    INSERT INTO users (email, username, password_hash, account_type) VALUES
      ('jmdelacruz@usep.edu.ph','jmdelacruz','$2y$10$7zUplIjRADM562c.KGOoi.NKXFCxjR9.aKBo.JLU7rkmWMfMAb/Cu','customer'),
      ('msantos@usep.edu.ph',   'msantos',   '$2y$10$xqk06Jr43jCDumVhQtGOy./LZgDhbH.pkXe3Vl1zwYKEgrnhIyVKi','customer'),
      ('rafael.lim@gmail.com',  'rafaellim', '$2y$10$uIFQbvnLz.76joj0xbzhoef2KkXGj8P/08R5lsXWoIvdtC1IKaF4e','customer'),
      ('areyes@usep.edu.ph',    'areyes',    '$2y$10$3JYUTUEMpT4fkVChMTRRnOMl/AeVh4dZpSjZ50damfeM/7rM/L.Cy','customer'),
      ('staff@gmail.com',       'mrobles',   '$2y$10$y1zDjOwq3k0XMAE1AVY77e2S0SH/pzAx3tbsAR.guPc1ZRBJr2Wu2','staff')
    ON DUPLICATE KEY UPDATE
      username = VALUES(username), password_hash = VALUES(password_hash),
      account_type = VALUES(account_type), is_active = 1;

    INSERT INTO customers (user_id, full_name, phone, address, university_id_no) VALUES
      ((SELECT id FROM users WHERE email='jmdelacruz@usep.edu.ph'),'Juan Miguel Dela Cruz','09175550123','Obrero, Davao City','2023-00412'),
      ((SELECT id FROM users WHERE email='msantos@usep.edu.ph'),   'Maria Santos',         '09175550188','Matina, Davao City','FAC-2019-0077'),
      ((SELECT id FROM users WHERE email='rafael.lim@gmail.com'),  'Rafael Lim',           '09175550241','Bajada, Davao City',NULL),
      ((SELECT id FROM users WHERE email='areyes@usep.edu.ph'),    'Ana Reyes',            '09175550356','Tagum City','2024-01180')
    -- uq_customers_user makes user_id the key, so each cast member keeps one row.
    ON DUPLICATE KEY UPDATE
      full_name = VALUES(full_name), phone = VALUES(phone),
      address = VALUES(address), university_id_no = VALUES(university_id_no);

    -- The WALK-IN: a customer with no login at all. Staff book on their behalf
    -- at the counter, which is why customers is decoupled from users.
    -- The walk-in has NO user_id, so uq_customers_user cannot key an upsert on
    -- them (multiple NULLs are allowed, by design). Matched on name instead.
    IF NOT EXISTS (SELECT 1 FROM customers WHERE full_name = 'Carmen Uy' AND user_id IS NULL) THEN
      INSERT INTO customers (user_id, full_name, phone, address, university_id_no)
        VALUES (NULL, 'Carmen Uy', '09175550499', 'Toril, Davao City', NULL);
    END IF;

    INSERT INTO staff (user_id, venue_id, full_name, employee_no, position_role, phone)
      VALUES ((SELECT id FROM users WHERE email='staff@gmail.com'),
              (SELECT id FROM venues WHERE venue_code='BAHAY-ALUMNI'),
              'Marites Robles','EMP-2021-0043','Venue Coordinator','09175550511')
    ON DUPLICATE KEY UPDATE
      venue_id = VALUES(venue_id), full_name = VALUES(full_name),
      position_role = VALUES(position_role), phone = VALUES(phone);
END$$

-- ---------------------------------------------------------------------------
-- sp_seed_maintenance — the four closure shapes, always current
--
--   until_date NULL  = indefinite — ends only when a human ends it
--   blocks_booking 1 = HARD   — the date cannot be booked at all
--   blocks_booking 0 = MEDIUM — still bookable, the customer is just told
-- ---------------------------------------------------------------------------
CREATE PROCEDURE sp_seed_maintenance ()
BEGIN
    INSERT INTO maintenance_windows (room_id, from_date, until_date, reason, blocks_booking) VALUES
      -- HARD + INDEFINITE, 80 days old -> closed, and past ~14 days it raises
      -- the "still closed?" review nag (a fixed window self-heals when its end
      -- date passes; an indefinite one only ends when someone acts)
      ((SELECT id FROM rooms WHERE room_code='r4'), CURDATE() - INTERVAL 80 DAY, NULL,
       'Roof repair', TRUE),
      -- MEDIUM, live now -> fully bookable, the customer just sees a notice.
      -- This is a medium closure's ONLY route to the customer, so one must
      -- always be live in the demo.
      ((SELECT id FROM rooms WHERE room_code='r6'), CURDATE() - INTERVAL 3 DAY, CURDATE() + INTERVAL 11 DAY,
       'One of two aircon units is being replaced', FALSE),
      -- HARD, a single day -> the day booking #20 has to book AROUND
      ((SELECT id FROM rooms WHERE room_code='r8'), CURDATE() + INTERVAL 19 DAY, CURDATE() + INTERVAL 19 DAY,
       'Floor refinishing', TRUE),
      -- HARD + PLANNED (starts in the future) -> same shape, no extra machinery
      ((SELECT id FROM rooms WHERE room_code='r2'), CURDATE() + INTERVAL 40 DAY, CURDATE() + INTERVAL 44 DAY,
       'Function room repainting', TRUE),
      -- MEDIUM on a HOSTEL room -> the hostel reuses the venue's window shape
      -- exactly; it never invented a second maintenance concept
      ((SELECT id FROM rooms WHERE room_code='h3'), CURDATE() - INTERVAL 2 DAY, CURDATE() + INTERVAL 13 DAY,
       'One of the two ceiling fans is being replaced', FALSE);
END$$

-- ---------------------------------------------------------------------------
-- sp_seed_booking_venue — one venue booking, its details and its per-day slots.
--
-- Venue days are INCLUSIVE: a 3-day booking starting Monday holds Mon, Tue, Wed.
-- Days under a HARD closure are SKIPPED, not held — a venue booking "books
-- around" a blocked day (a hostel stay must be contiguous and is refused
-- instead). That decision is asked of fn_room_hard_blocked(), the same function
-- sp_add_venue_slot() uses, so the seed can never hold a day the app would
-- refuse to sell.
-- ---------------------------------------------------------------------------
CREATE PROCEDURE sp_seed_booking_venue (
    IN  p_who        VARCHAR(190),   -- login email, or the walk-in's name
    IN  p_room       VARCHAR(40),
    IN  p_day_offset INT,            -- first day, relative to today
    IN  p_days       INT,            -- inclusive day count
    IN  p_start      TIME,
    IN  p_end        TIME,
    IN  p_res        VARCHAR(40),
    IN  p_pay        VARCHAR(40),
    IN  p_method     VARCHAR(10),    -- 'gcash' | 'cash' | '' for none yet
    IN  p_usep       BOOLEAN,        -- the customer's CLAIM for this booking
    IN  p_verified   BOOLEAN,        -- staff verified the USeP ID -> discount
    IN  p_refunds    BOOLEAN,        -- the policy snapshot: 1 = pre-pay
    IN  p_event      VARCHAR(190),
    IN  p_attendees  INT,
    OUT p_id         BIGINT UNSIGNED
)
BEGIN
    DECLARE v_cust  BIGINT UNSIGNED;
    DECLARE v_room  BIGINT UNSIGNED;
    DECLARE v_fee   DECIMAL(12,2);
    DECLARE v_price DECIMAL(12,2);
    DECLARE v_pct   DECIMAL(5,2);
    DECLARE v_disc  DECIMAL(12,2);
    DECLARE v_start DATE;
    DECLARE v_end   DATE;
    DECLARE v_sub   DATETIME;
    DECLARE v_appr  DATETIME;
    DECLARE v_comp  DATETIME;
    DECLARE i       INT DEFAULT 0;
    DECLARE v_held  INT DEFAULT 0;

    SET v_cust = fn_seed_customer(p_who);
    SELECT r.id, d.fee_per_day INTO v_room, v_fee
      FROM rooms r JOIN event_room_details d ON d.room_id = r.id
     WHERE r.room_code = p_room;

    SET v_start = CURDATE() + INTERVAL p_day_offset DAY;
    SET v_end   = v_start   + INTERVAL (p_days - 1) DAY;
    SET v_price = v_fee * p_days;
    -- The discount is earned by a VERIFIED USeP ID, never by the claim alone
    -- and never by an @usep.edu.ph address (DB-DECISIONS #3). The rate is
    -- snapshotted here and never re-read, so changing it later cannot re-price
    -- a booking that was already quoted (#2).
    SET v_pct   = IF(p_usep AND p_verified, fn_current_discount_percent(), 0);
    SET v_disc  = ROUND(v_price * v_pct / 100, 2);

    -- Booked 25 days before the event, but never in the future: unclamped, a
    -- far-off event yields a "booked on" date weeks from now, which reads as
    -- nonsense on the history table.
    SET v_sub  = LEAST(TIMESTAMP(v_start - INTERVAL 25 DAY), NOW() - INTERVAL 3 DAY);
    SET v_appr = IF(p_res IN ('approved','completed','released','disrupted'), v_sub + INTERVAL 1 DAY, NULL);
    SET v_comp = IF(p_res = 'completed', TIMESTAMP(v_end + INTERVAL 1 DAY), NULL);

    INSERT INTO bookings (
        customer_id, room_id, booking_type, reservation_status, payment_status,
        payment_method, is_usep_affiliated, affiliation_verified,
        room_price, discount_percent, discount_amount, total_amount,
        refunds_allowed, submitted_at, approved_at, completed_at,
        cancelled_at, updated_by_user_id
    ) VALUES (
        v_cust, v_room, 'venue', p_res, p_pay,
        NULLIF(p_method, ''), p_usep, p_verified,
        v_price, v_pct, v_disc, v_price - v_disc,
        p_refunds, v_sub, v_appr, v_comp,
        IF(p_res = 'cancelled', v_sub + INTERVAL 2 DAY, NULL), NULL
    );
    SET p_id = LAST_INSERT_ID();

    INSERT INTO venue_booking_details (booking_id, event_name, start_date, end_date, attendee_count)
    VALUES (p_id, p_event, v_start, v_end, p_attendees);

    WHILE i < p_days DO
        IF NOT fn_room_hard_blocked(v_room, v_start + INTERVAL i DAY) THEN
            INSERT INTO venue_booking_slots (booking_id, room_id, slot_date, start_time, end_time, released_at)
            VALUES (p_id, v_room, v_start + INTERVAL i DAY, p_start, p_end,
                    -- a booking that no longer holds anything releases its days
                    IF(p_res IN ('released','rejected','cancelled'), NOW(), NULL));
            SET v_held = v_held + 1;
        END IF;
        SET i = i + 1;
    END WHILE;

    -- The pay-by moment, from the SAME function the application uses, so the
    -- seed and the running system can never disagree about a deadline.
    UPDATE bookings SET current_deadline_at = fn_payment_deadline(p_id) WHERE id = p_id;
END$$

-- ---------------------------------------------------------------------------
-- sp_seed_booking_hostel — one hostel booking + its stay header.
-- Occupants and beds are added separately by sp_seed_add_occupant().
--
-- Hostel nights are EXCLUSIVE of check-out: Aug 1 -> Aug 4 = 3 nights.
-- Venues count days INCLUSIVE. Same two dates, different arithmetic.
-- Price is per HEAD per NIGHT: beds x rate x nights.
-- ---------------------------------------------------------------------------
CREATE PROCEDURE sp_seed_booking_hostel (
    IN  p_who        VARCHAR(190),
    IN  p_room       VARCHAR(40),
    IN  p_day_offset INT,            -- check-in, relative to today
    IN  p_nights     INT,
    IN  p_beds       INT,
    IN  p_res        VARCHAR(40),
    IN  p_pay        VARCHAR(40),
    IN  p_method     VARCHAR(10),
    IN  p_usep       BOOLEAN,
    IN  p_verified   BOOLEAN,
    IN  p_refunds    BOOLEAN,
    IN  p_pos        VARCHAR(100),   -- '' = CEDU has not issued one yet
    IN  p_or         VARCHAR(100),   -- '' = the Cashier has not issued one yet
    IN  p_checked_in BOOLEAN,
    OUT p_id         BIGINT UNSIGNED
)
BEGIN
    DECLARE v_cust  BIGINT UNSIGNED;
    DECLARE v_room  BIGINT UNSIGNED;
    DECLARE v_rate  DECIMAL(12,2);
    DECLARE v_price DECIMAL(12,2);
    DECLARE v_pct   DECIMAL(5,2);
    DECLARE v_disc  DECIMAL(12,2);
    DECLARE v_ci    DATE;
    DECLARE v_co    DATE;
    DECLARE v_sub   DATETIME;

    SET v_cust = fn_seed_customer(p_who);
    SELECT r.id, d.rate_per_head_per_night INTO v_room, v_rate
      FROM rooms r JOIN hostel_room_details d ON d.room_id = r.id
     WHERE r.room_code = p_room;

    SET v_ci    = CURDATE() + INTERVAL p_day_offset DAY;
    SET v_co    = v_ci + INTERVAL p_nights DAY;          -- check-out is not a night
    SET v_price = v_rate * p_beds * p_nights;
    SET v_pct   = IF(p_usep AND p_verified, fn_current_discount_percent(), 0);
    SET v_disc  = ROUND(v_price * v_pct / 100, 2);
    SET v_sub   = LEAST(TIMESTAMP(v_ci - INTERVAL 12 DAY), NOW() - INTERVAL 2 DAY);

    INSERT INTO bookings (
        customer_id, room_id, booking_type, reservation_status, payment_status,
        payment_method, is_usep_affiliated, affiliation_verified,
        room_price, discount_percent, discount_amount, total_amount,
        refunds_allowed, submitted_at, approved_at, completed_at
    ) VALUES (
        v_cust, v_room, 'hostel', p_res, p_pay,
        NULLIF(p_method, ''), p_usep, p_verified,
        v_price, v_pct, v_disc, v_price - v_disc,
        p_refunds, v_sub,
        IF(p_res IN ('approved','completed'), v_sub + INTERVAL 1 DAY, NULL),
        IF(p_res = 'completed', TIMESTAMP(v_co + INTERVAL 1 DAY), NULL)
    );
    SET p_id = LAST_INSERT_ID();

    -- The OR is NOT a payment status: payment ends at confirmed. It is a
    -- post-confirmation DOCUMENT, which is why it lives here with a nullable
    -- number rather than as a state the booking sits in.
    INSERT INTO hostel_booking_details (
        booking_id, check_in_date, check_out_date,
        pos_number, pos_recorded_at, official_receipt_no, official_receipt_recorded_at,
        checked_in, checked_in_at
    ) VALUES (
        p_id, v_ci, v_co,
        NULLIF(p_pos, ''), IF(p_pos = '', NULL, v_sub + INTERVAL 2 DAY),
        NULLIF(p_or, ''),  IF(p_or  = '', NULL, TIMESTAMP(v_co + INTERVAL 1 DAY)),
        p_checked_in, IF(p_checked_in, TIMESTAMP(v_ci) + INTERVAL 14 HOUR, NULL)
    );

    UPDATE bookings SET current_deadline_at = fn_payment_deadline(p_id) WHERE id = p_id;
END$$

-- ---------------------------------------------------------------------------
-- sp_seed_add_occupant — one named guest in one EXACT bed for the whole stay.
--
-- beds_taken is never stored: it is COUNT(occupants), and the occupancy of a
-- room on a night is simply how many night-rows point at its beds. The bed
-- chosen is the first ACTIVE bed in the room that is free for every night of
-- the stay, which is the same question the booking page asks — so the seed
-- cannot create an overlap the UNIQUE(active_bed_id, night_date) guard would
-- have refused.
-- ---------------------------------------------------------------------------
CREATE PROCEDURE sp_seed_add_occupant (
    IN p_booking_id BIGINT UNSIGNED,
    IN p_name       VARCHAR(190),
    IN p_gender     VARCHAR(10),      -- 'male' | 'female' | 'other'
    IN p_primary    BOOLEAN
)
BEGIN
    DECLARE v_room BIGINT UNSIGNED;
    DECLARE v_ci   DATE;
    DECLARE v_co   DATE;
    DECLARE v_occ  BIGINT UNSIGNED;
    DECLARE v_bed  BIGINT UNSIGNED;
    DECLARE v_res  BIGINT UNSIGNED;
    DECLARE v_n    DATE;

    SELECT b.room_id, h.check_in_date, h.check_out_date INTO v_room, v_ci, v_co
      FROM bookings b JOIN hostel_booking_details h ON h.booking_id = b.id
     WHERE b.id = p_booking_id;

    INSERT INTO hostel_occupants (booking_id, full_name, gender, is_primary_guest)
    VALUES (p_booking_id, p_name, p_gender, p_primary);
    SET v_occ = LAST_INSERT_ID();

    SELECT hb.id INTO v_bed
      FROM hostel_beds hb
     WHERE hb.room_id = v_room AND hb.is_active = 1
       AND NOT EXISTS (
             SELECT 1 FROM bed_reservation_nights n
              WHERE n.bed_id = hb.id AND n.released_at IS NULL
                AND n.night_date >= v_ci AND n.night_date < v_co)
     ORDER BY hb.display_order, hb.id
     LIMIT 1;

    IF v_bed IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Seed: no free bed for the whole stay.';
    END IF;

    INSERT INTO bed_reservations (booking_id, occupant_id, bed_id, check_in_date, check_out_date)
    VALUES (p_booking_id, v_occ, v_bed, v_ci, v_co);
    SET v_res = LAST_INSERT_ID();

    SET v_n = v_ci;
    WHILE v_n < v_co DO                      -- EXCLUSIVE of check-out
        INSERT INTO bed_reservation_nights (bed_reservation_id, booking_id, bed_id, night_date)
        VALUES (v_res, p_booking_id, v_bed, v_n);
        SET v_n = v_n + INTERVAL 1 DAY;
    END WHILE;
END$$

-- ---------------------------------------------------------------------------
-- sp_seed_payment — the money row for a booking that has paid (or tried to)
-- ---------------------------------------------------------------------------
CREATE PROCEDURE sp_seed_payment (
    IN p_booking_id BIGINT UNSIGNED,
    IN p_status     VARCHAR(20),     -- submitted|under_review|confirmed|rejected|refunded
    IN p_days_ago   INT,
    OUT p_id        BIGINT UNSIGNED
)
BEGIN
    DECLARE v_amt    DECIMAL(12,2);
    DECLARE v_method VARCHAR(10);
    SELECT total_amount, COALESCE(payment_method, 'gcash') INTO v_amt, v_method
      FROM bookings WHERE id = p_booking_id;

    INSERT INTO payments (booking_id, payment_method, amount, payment_record_status,
                          paid_at, confirmed_at, confirmed_by_user_id)
    VALUES (p_booking_id, v_method, v_amt, p_status,
            NOW() - INTERVAL p_days_ago DAY,
            IF(p_status IN ('confirmed','refunded'), NOW() - INTERVAL GREATEST(p_days_ago - 1, 0) DAY, NULL),
            IF(p_status IN ('confirmed','refunded'), (SELECT id FROM users WHERE email='staff@gmail.com'), NULL));
    SET p_id = LAST_INSERT_ID();
END$$

-- ===========================================================================
--  sp_seed_demo — THE SHOWCASE
--
--  Two layers, on purpose:
--    A-F  ~37 bookings, each one chosen to make ONE feature visible. Between
--         them they cover every reservation status, every payment status both
--         policies can reach, both booking types, all three affiliation cases,
--         every queue tab, all three receipt verdicts and the whole refund
--         chain. This layer proves the system WORKS.
--    G    ~120 ordinary completed bookings across five quarters. Deliberately
--         boring. Without them Quarterly Reports charts four quarters out of a
--         handful of deliberately-odd rows and looks broken; this layer makes
--         the system look LIVED IN.
-- ===========================================================================
CREATE PROCEDURE sp_seed_demo ()
BEGIN
    DECLARE b   BIGINT UNSIGNED;      -- the booking just created
    DECLARE pay BIGINT UNSIGNED;      -- the payment just created
    DECLARE i   INT DEFAULT 0;
    DECLARE v_room VARCHAR(40);
    DECLARE v_off  INT;

    CALL sp_seed_reset();
    CALL sp_seed_cast();
    CALL sp_seed_maintenance();

    -- =====================================================================
    -- A. PRE-PAY POLICY  (refunds_allowed = 1 -> pay before the event)
    -- =====================================================================
    -- 1  Queue tab "Pending ID" + the verified USeP discount
    CALL sp_seed_booking_venue('jmdelacruz@usep.edu.ph','r1',14,1,'08:00','17:00','pending','locked','gcash',1,1,1,'College Awards Night',210,b);
    INSERT INTO booking_documents (booking_id, document_type, file_path, original_filename, verification_status)
      VALUES (b,'customer_id','private/ids/seed-usep-id.jpg','usep-id.jpg','pending');

    -- 2  USeP CLAIMED but NOT verified -> full price. The address alone never
    --    grants the discount; nothing verifies it at registration.
    CALL sp_seed_booking_venue('msantos@usep.edu.ph','r7',12,1,'13:00','17:00','pending','locked','gcash',1,0,1,'Faculty Research Colloquium',45,b);
    INSERT INTO booking_documents (booking_id, document_type, file_path, original_filename, verification_status)
      VALUES (b,'customer_id','private/ids/seed-faculty-id.jpg','faculty-id.jpg','pending');

    -- 3  Approved, payment open, deadline = the day before (fn_payment_deadline)
    CALL sp_seed_booking_venue('rafael.lim@gmail.com','r6',10,1,'09:00','12:00','approved','await_gcash','gcash',0,0,1,'Org Leadership Summit',90,b);

    -- 4  Queue tab "Manual review" — a receipt a human must weigh
    CALL sp_seed_booking_venue('customer@gmail.com','r2',8,1,'08:00','12:00','approved','under_review','gcash',0,0,1,'Alumni Chapter Meeting',60,b);
    CALL sp_seed_payment(b,'under_review',1,pay);
    INSERT INTO gcash_receipts (payment_id, booking_id, attempt_no, file_path, reference_number,
                                receiver_name, receiver_number, amount_centavos, receipt_datetime,
                                sha256_hash, ocr_confidence, verdict, flags_json)
      VALUES (pay,b,1,'private/receipts/seed-review.jpg','3042137089838','M... J... J.','09951941234',
              250000, NOW() - INTERVAL 1 DAY, REPEAT('a',64), 46.20,'manual_review',
              '["receiver_unreadable","low_confidence","ref_format"]');

    -- 5  Queue tab "Receipts to confirm" — auto-passed, waiting on staff
    CALL sp_seed_booking_venue('jmdelacruz@usep.edu.ph','r5',7,1,'07:00','19:00','approved','under_review','gcash',1,1,1,'Intercollege Sportsfest',700,b);
    CALL sp_seed_payment(b,'submitted',1,pay);
    INSERT INTO gcash_receipts (payment_id, booking_id, attempt_no, file_path, reference_number,
                                receiver_name, receiver_number, amount_centavos, receipt_datetime,
                                sha256_hash, ocr_confidence, verdict, flags_json)
      VALUES (pay,b,1,'private/receipts/seed-accepted.jpg','3042137090114','Ramon T Villaflor','09183345566',
              (SELECT ROUND(total_amount*100) FROM bookings WHERE id=b), NOW() - INTERVAL 1 DAY,
              REPEAT('b',64), 91.40,'accepted','[]');

    -- 6  WALK-IN paying cash — a customer with no login at all
    CALL sp_seed_booking_venue('Carmen Uy','r3',6,1,'10:00','12:00','approved','await_cash','cash',0,0,1,'Thesis Defense Panel',18,b);

    -- 7  Cash confirmed at the office
    CALL sp_seed_booking_venue('customer@gmail.com','r7',5,1,'08:00','12:00','approved','paid_cash','cash',0,0,1,'Licensure Review Session',50,b);
    CALL sp_seed_payment(b,'confirmed',2,pay);

    -- 8  Queue tab "Overdue" — pre-pay deadline passed, the slot is releasable.
    --    The event is TODAY on purpose: pre-pay is due 23:59 the day BEFORE the
    --    first day, so a booking further out cannot honestly be overdue yet.
    --    Dating this one +3 would print "Overdue" beside a pay-by date that has
    --    not arrived — the sort of self-contradiction seed data must never show.
    CALL sp_seed_booking_venue('rafael.lim@gmail.com','r8',0,1,'08:00','17:00','approved','overdue','gcash',0,0,1,'Student Org Orientation',150,b);

    -- 9  Never paid -> the hold was released and the payment expired.
    --    Same reasoning: the deadline has to have passed for this to be true.
    CALL sp_seed_booking_venue('msantos@usep.edu.ph','r2',0,1,'13:00','17:00','released','expired','gcash',1,0,1,'Department Planning Session',55,b);

    -- 10 Staff rejected the request outright
    CALL sp_seed_booking_venue('customer@gmail.com','r6',9,1,'18:00','21:00','rejected','locked','gcash',0,0,1,'Film Screening Night',100,b);

    -- 11 A finished, fully paid booking
    CALL sp_seed_booking_venue('jmdelacruz@usep.edu.ph','r1',-20,1,'17:00','22:00','completed','confirmed','gcash',1,1,1,'Leadership Recognition Night',250,b);
    CALL sp_seed_payment(b,'confirmed',22,pay);

    -- 12 Cancelled and the money returned
    CALL sp_seed_booking_venue('customer@gmail.com','r2',-30,1,'08:00','12:00','cancelled','refunded','gcash',0,0,1,'Organization Planning Session',60,b);
    CALL sp_seed_payment(b,'refunded',32,pay);

    -- =====================================================================
    -- B. POST-PAY POLICY  (refunds_allowed = 0 — the USeP default)
    --    Payment is LOCKED until the last day has passed, then due within
    --    postpay_grace_days. Missed = overdue, and nothing is released.
    -- =====================================================================
    -- 13 Future event -> payment pending, cannot be paid yet
    CALL sp_seed_booking_venue('jmdelacruz@usep.edu.ph','r5',21,1,'08:00','17:00','approved','await_event','gcash',1,1,0,'University General Assembly',800,b);

    -- 14 The event ended yesterday -> the payment window just opened
    CALL sp_seed_booking_venue('rafael.lim@gmail.com','r8',-1,1,'09:00','16:00','approved','await_gcash','gcash',0,0,0,'Campus Wellness Fair',180,b);

    -- 15 Queue tab "Overdue", post-pay flavour: the window closed, the booking
    --    is NOT released, and it can still be paid late
    CALL sp_seed_booking_venue('customer@gmail.com','r7',-9,1,'13:00','17:00','completed','overdue','gcash',0,0,0,'Digital Literacy Workshop',55,b);

    -- 16 A normal post-pay completion
    CALL sp_seed_booking_venue('msantos@usep.edu.ph','r6',-15,1,'09:00','12:00','completed','confirmed','cash',1,1,0,'Research Documentary Screening',110,b);
    CALL sp_seed_payment(b,'confirmed',13,pay);

    -- 17 A brand-new request under the post-pay policy
    CALL sp_seed_booking_venue('Carmen Uy','r3',16,1,'09:00','11:00','pending','locked','cash',0,0,0,'Committee Meeting',16,b);

    -- =====================================================================
    -- C. VENUE SCHEDULING SHAPES
    -- =====================================================================
    -- 18 Multi-day, the SAME hours every day
    CALL sp_seed_booking_venue('jmdelacruz@usep.edu.ph','r1',25,3,'13:00','18:00','approved','await_event','gcash',1,1,0,'Graduation Ball Rehearsals',280,b);

    -- 19 Multi-day with DIFFERENT hours per day — which is why per-day times
    --    live in venue_booking_slots and not on the booking header (#8)
    CALL sp_seed_booking_venue('rafael.lim@gmail.com','r5',30,3,'08:00','12:00','approved','await_event','gcash',0,0,0,'Regional Athletics Meet',900,b);
    UPDATE venue_booking_slots SET start_time='07:00', end_time='19:00'
     WHERE booking_id=b AND slot_date = CURDATE() + INTERVAL 31 DAY;
    UPDATE venue_booking_slots SET start_time='13:00', end_time='17:00'
     WHERE booking_id=b AND slot_date = CURDATE() + INTERVAL 32 DAY;

    -- 20 BOOKS AROUND a hard closure: 3 days requested across the blocked day,
    --    so only 2 are held. (A hostel stay must be contiguous and would be
    --    refused instead — the two models differ on purpose.)
    CALL sp_seed_booking_venue('customer@gmail.com','r8',18,3,'08:00','17:00','approved','await_event','gcash',0,0,0,'Training Workshop Series',150,b);

    -- 21 The conflict a customer meets: r2 is taken on this day
    CALL sp_seed_booking_venue('msantos@usep.edu.ph','r2',11,1,'08:00','12:00','approved','await_gcash','gcash',1,1,1,'Faculty Development Seminar',70,b);

    -- =====================================================================
    -- D. HOSTEL — per bed, per night, and the CEDU payment route
    -- =====================================================================
    -- 22 Hostel ID review, 2 beds x 3 nights
    CALL sp_seed_booking_hostel('areyes@usep.edu.ph','h1',9,3,2,'pending','locked','gcash',1,1,0,'','',0,b);
    CALL sp_seed_add_occupant(b,'Ana Reyes','female',1);
    CALL sp_seed_add_occupant(b,'Bea Cruz','female',0);

    -- 23 Queue tab "POS to fetch" — waiting on ANOTHER OFFICE, not the customer.
    --    Payment stays locked until CEDU issues the POS.
    CALL sp_seed_booking_hostel('jmdelacruz@usep.edu.ph','h4',7,2,1,'approved','await_pos','gcash',1,1,0,'','',0,b);
    CALL sp_seed_add_occupant(b,'Juan Miguel Dela Cruz','male',1);

    -- 24 POS recorded -> payment unlocked
    CALL sp_seed_booking_hostel('areyes@usep.edu.ph','h2',5,2,2,'approved','await_gcash','gcash',1,1,0,'POS-2026-04417','',0,b);
    CALL sp_seed_add_occupant(b,'Ana Reyes','female',1);
    CALL sp_seed_add_occupant(b,'Kim Bautista','female',0);

    -- 25 Paid and confirmed, but the Official Receipt is still to come from the
    --    University Cashier. The OR is a DOCUMENT, never a payment status.
    CALL sp_seed_booking_hostel('customer@gmail.com','h5',3,2,1,'approved','confirmed','gcash',0,0,0,'POS-2026-04390','',0,b);
    CALL sp_seed_add_occupant(b,'Brent Cajipoe','male',1);
    CALL sp_seed_payment(b,'confirmed',2,pay);

    -- 26 The whole hostel flow finished: POS, paid, OR issued, guest checked in
    CALL sp_seed_booking_hostel('areyes@usep.edu.ph','h1',-6,3,1,'completed','confirmed','cash',1,1,0,'POS-2026-04201','OR-2026-88117',1,b);
    CALL sp_seed_add_occupant(b,'Ana Reyes','female',1);
    CALL sp_seed_payment(b,'confirmed',5,pay);

    -- 27 Post-pay hostel: the POS is fetched AFTER check-out (DB-DECISIONS #18)
    CALL sp_seed_booking_hostel('msantos@usep.edu.ph','h3',28,2,1,'approved','await_event','gcash',1,1,0,'','',0,b);
    CALL sp_seed_add_occupant(b,'Maria Santos','female',1);

    -- 28 ALL SIX BEDS — exclusivity is EMERGENT. Nothing stored says
    --    "exclusive"; the count simply reached the room's bed total.
    CALL sp_seed_booking_hostel('rafael.lim@gmail.com','h2',14,2,6,'approved','confirmed','gcash',0,0,0,'POS-2026-04455','',0,b);
    CALL sp_seed_add_occupant(b,'Rafael Lim','male',1);
    CALL sp_seed_add_occupant(b,'Dan Lim','male',0);
    CALL sp_seed_add_occupant(b,'Elmo Tan','male',0);
    CALL sp_seed_add_occupant(b,'Fritz Uy','male',0);
    CALL sp_seed_add_occupant(b,'Gab Ong','male',0);
    CALL sp_seed_add_occupant(b,'Hero Sy','male',0);
    CALL sp_seed_payment(b,'confirmed',1,pay);

    -- 29 A PARTLY full room with a mixed roster — the case that only exists
    --    because booking is per bed. Gender is DISPLAY ONLY; nothing is locked.
    CALL sp_seed_booking_hostel('areyes@usep.edu.ph','h4',14,2,3,'approved','confirmed','gcash',1,1,0,'POS-2026-04461','',0,b);
    CALL sp_seed_add_occupant(b,'Ana Reyes','female',1);
    CALL sp_seed_add_occupant(b,'Luis Ramos','male',0);
    CALL sp_seed_add_occupant(b,'Nico Perez','other',0);
    CALL sp_seed_payment(b,'confirmed',1,pay);

    -- =====================================================================
    -- E. THE REFUND CHAIN  (only on bookings made while refunds were ON)
    --    A refund is STAFF work: it gets its own queue tab because nobody
    --    else surfaces it and the customer is already waiting.
    -- =====================================================================
    -- 30 Filed, waiting on staff -> queue tab "Refunds to verify"
    CALL sp_seed_booking_venue('customer@gmail.com','r1',12,1,'18:00','23:00','approved','refund_requested','gcash',0,0,1,'Company Anniversary Dinner',200,b);
    CALL sp_seed_payment(b,'confirmed',9,pay);
    INSERT INTO refunds (booking_id,payment_id,refund_status,reason_category,reason,amount_requested,refund_to_number,requested_at)
      VALUES (b,pay,'requested','event_cancelled','Our guest speaker cancelled and we cannot hold the event.',
              (SELECT total_amount FROM bookings WHERE id=b),'09175550123', NOW() - INTERVAL 4 DAY);

    -- 31 Returned for correction — a PAPERWORK problem, NOT a denial. The
    --    booking is untouched and the customer may fix and resubmit.
    CALL sp_seed_booking_venue('msantos@usep.edu.ph','r7',15,1,'08:00','12:00','approved','refund_correction','gcash',1,1,1,'Faculty Workshop',50,b);
    CALL sp_seed_payment(b,'confirmed',11,pay);
    INSERT INTO refunds (booking_id,payment_id,refund_status,reason_category,reason,amount_requested,refund_to_number,correction_attempts,correction_due_at,requested_at,reviewed_at,notes)
      VALUES (b,pay,'returned_for_correction','schedule_conflict','Clashes with the accreditation visit.',
              (SELECT total_amount FROM bookings WHERE id=b),'09175550188',1, NOW() + INTERVAL 5 DAY,
              NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 2 DAY,'The GCash receipt image is unreadable — please re-upload it.');

    -- 32 Approved, waiting on the Official Receipt before any money moves
    CALL sp_seed_booking_venue('rafael.lim@gmail.com','r6',20,1,'13:00','17:00','approved','refund_await_or','gcash',0,0,1,'Product Launch Preview',100,b);
    CALL sp_seed_payment(b,'confirmed',14,pay);
    INSERT INTO refunds (booking_id,payment_id,refund_status,reason_category,reason,amount_requested,amount_approved,refund_to_number,official_receipt_pending,requested_at,reviewed_at)
      VALUES (b,pay,'approved','event_cancelled','Sponsor withdrew.',
              (SELECT total_amount FROM bookings WHERE id=b),(SELECT total_amount FROM bookings WHERE id=b),
              '09175550241',1, NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 3 DAY);

    -- 33 Payout in flight
    CALL sp_seed_booking_venue('jmdelacruz@usep.edu.ph','r2',18,1,'08:00','12:00','approved','refund_processing','gcash',1,1,1,'Student Council Assembly',70,b);
    CALL sp_seed_payment(b,'confirmed',16,pay);
    INSERT INTO refunds (booking_id,payment_id,refund_status,reason_category,reason,amount_requested,amount_approved,refund_to_number,official_receipt_pending,requested_at,reviewed_at)
      VALUES (b,pay,'approved','wrong_room','Booked the wrong hall.',
              (SELECT total_amount FROM bookings WHERE id=b),(SELECT total_amount FROM bookings WHERE id=b),
              '09175550123',0, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 5 DAY);

    -- 34 Paid out. ONLY NOW is the booking closed and its date freed.
    CALL sp_seed_booking_venue('customer@gmail.com','r8',-12,1,'08:00','17:00','cancelled','refunded','gcash',0,0,1,'Regional Training Camp',150,b);
    CALL sp_seed_payment(b,'refunded',25,pay);
    INSERT INTO refunds (booking_id,payment_id,refund_status,reason_category,reason,amount_requested,amount_approved,refund_to_number,payout_reference,requested_at,reviewed_at,completed_at)
      VALUES (b,pay,'completed','venue_unavailable','Room closed for repairs.',
              (SELECT total_amount FROM bookings WHERE id=b),(SELECT total_amount FROM bookings WHERE id=b),
              '09175550499','3042137101992', NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 16 DAY, NOW() - INTERVAL 14 DAY);

    -- 35 DENIED — final, and the booking is kept exactly as it was
    CALL sp_seed_booking_venue('msantos@usep.edu.ph','r3',-5,1,'09:00','11:00','completed','refund_denied','gcash',1,1,1,'Panel Interview Session',18,b);
    CALL sp_seed_payment(b,'confirmed',28,pay);
    INSERT INTO refunds (booking_id,payment_id,refund_status,reason_category,reason,amount_requested,refund_to_number,requested_at,reviewed_at,notes)
      VALUES (b,pay,'rejected','other','Changed our minds after the event.',
              (SELECT total_amount FROM bookings WHERE id=b),'09175550188', NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 1 DAY,
              'The event already took place; a delivered service is not refundable.');

    -- 36 WITHDRAWN by the customer before any decision — booking intact
    CALL sp_seed_booking_venue('rafael.lim@gmail.com','r5',24,1,'08:00','17:00','approved','confirmed','gcash',0,0,1,'Corporate Sportsfest',650,b);
    CALL sp_seed_payment(b,'confirmed',6,pay);
    INSERT INTO refunds (booking_id,payment_id,refund_status,reason_category,reason,amount_requested,refund_to_number,requested_at,withdrawn_at)
      VALUES (b,pay,'withdrawn','schedule_conflict','Thought we had a clash.',
              (SELECT total_amount FROM bookings WHERE id=b),'09175550241', NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 1 DAY);

    -- =====================================================================
    -- F. THE EDGE CASE
    -- =====================================================================
    -- 37 DISRUPTED — USeP closed the room while a PAID booking sat inside the
    --    closure. The customer is owed a replacement room, a new date, or a
    --    non-deniable refund. The data model carries the state; the flow is
    --    designed but NOT built (PROJECT-HANDOFF 5), so this row exists to
    --    prove the schema handles it, not to be clicked through.
    CALL sp_seed_booking_venue('jmdelacruz@usep.edu.ph','r4',-40,1,'16:00','21:00','disrupted','confirmed','gcash',1,1,1,'Garden Reception [demo: room closed after booking]',120,b);
    CALL sp_seed_payment(b,'confirmed',45,pay);

    -- =====================================================================
    -- G. BULK HISTORY — five quarters of ordinary, finished business.
    --    Boring on purpose: this is what makes the reports and the dashboard
    --    counters look like a system in use rather than a demo.
    -- =====================================================================
    WHILE i < 120 DO
        SET v_room = ELT(1 + (i % 8), 'r1','r2','r3','r4','r5','r6','r7','r8');
        SET v_off  = -(35 + (i * 3) + (i % 7));        -- spread back over ~15 months
        CALL sp_seed_booking_venue(
            ELT(1 + (i % 5), 'customer@gmail.com','jmdelacruz@usep.edu.ph','msantos@usep.edu.ph','rafael.lim@gmail.com','Carmen Uy'),
            v_room, v_off, 1 + (i % 2), '08:00', '17:00',
            'completed', IF(i % 9 = 0, 'paid_cash', 'confirmed'),
            IF(i % 9 = 0, 'cash', 'gcash'),
            (i % 3 = 0), (i % 3 = 0), (i % 4 = 0),
            ELT(1 + (i % 6), 'Departmental Seminar','Training Session','Alumni Gathering','Student Assembly','Faculty Meeting','Community Outreach'),
            20 + (i % 80), b);
        CALL sp_seed_payment(b, 'confirmed', ABS(v_off) - 1, pay);
        SET i = i + 1;
    END WHILE;

    SELECT CONCAT('sp_seed_demo complete — ',
                  (SELECT COUNT(*) FROM bookings), ' bookings, ',
                  (SELECT COUNT(*) FROM payments), ' payments, ',
                  (SELECT COUNT(*) FROM refunds), ' refunds, ',
                  (SELECT COUNT(*) FROM maintenance_windows), ' closures, built around ',
                  CURDATE()) AS result;
END$$

DELIMITER ;
