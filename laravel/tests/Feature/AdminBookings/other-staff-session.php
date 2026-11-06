<?php
/* Another staff member, for TwoPeopleActingTest. In its OWN process, so it can hold a booking's
   turn while the test's request waits for it. It records the cash the way the action does, then
   lets go. Argument: base64 JSON {dsn, user, pass, lock, booking, flag}. */
$a = json_decode(base64_decode($argv[1] ?? ''), true);
$pdo = new PDO($a['dsn'], $a['user'], $a['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$got = $pdo->prepare('SELECT GET_LOCK(?, 5)');
$got->execute([$a['lock']]);
$got->fetchAll();
touch($a['flag']);
sleep(3);   // long enough for the test's request to reach its read
$pdo->prepare("UPDATE bookings SET payment_status = 'paid_cash' WHERE id = ?")->execute([$a['booking']]);
$pdo->prepare("INSERT INTO payments (booking_id, payment_method, amount, payment_record_status, paid_at, confirmed_at)
               SELECT id, 'cash', total_amount, 'confirmed', NOW(), NOW() FROM bookings WHERE id = ?")->execute([$a['booking']]);
$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$a['lock']]);
