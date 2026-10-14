<?php
/**
 * One-time seeder: creates the branches, per-branch services, staff
 * (employees + backing user accounts), and promotions the customer
 * dashboard needs, plus a demo customer account with sample appointments,
 * reviews, and home service requests. This is the same data that used to
 * be hardcoded in the frontend JS — now real rows the backend queries.
 *
 * Idempotent: safe to re-run, existing rows are matched and left alone.
 *
 * Run from the command line:
 *   php database/seeders/seed_customer_demo.php
 */

require_once __DIR__ . '/../../backend/config/database.php';

$pdo = Database::getInstance();

function upsertAndGetId(PDO $pdo, string $table, string $uniqueCol, $uniqueVal, array $columns): int
{
    $cols = array_keys($columns);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $updateList = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols));
    $stmt = $pdo->prepare("INSERT INTO `$table` ($colList) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updateList");
    $stmt->execute(array_values($columns));

    $select = $pdo->prepare("SELECT id FROM `$table` WHERE `$uniqueCol` = ?");
    $select->execute([$uniqueVal]);
    return (int) $select->fetchColumn();
}

$pdo->beginTransaction();

try {
    $branchIds = [
        'daraga'   => upsertAndGetId($pdo, 'branches', 'branch_key', 'daraga', ['branch_key' => 'daraga', 'branch_name' => 'Leo Mejillano Salon (Daraga)']),
        'yashano'  => upsertAndGetId($pdo, 'branches', 'branch_key', 'yashano', ['branch_key' => 'yashano', 'branch_name' => 'Skin Brows (Yashano)']),
        'cabangan' => upsertAndGetId($pdo, 'branches', 'branch_key', 'cabangan', ['branch_key' => 'cabangan', 'branch_name' => 'Lash & Brows (Cabangan)']),
    ];

    $serviceCatalog = [
        'daraga' => [
            ['Signature Haircut & Blow Dry', 30, 'Complete Salon Services', '45 min', 45],
            ['Organic Rebonding (Any Length)', 999, 'Hair & Scalp Treatments', '3-4 hrs', 210],
            ['Brazilian Treatment / Botox', 899, 'Hair & Scalp Treatments', '2-3 hrs', 150],
            ['Hair Color with Keratin', 499, 'Hair & Scalp Treatments', '2 hrs', 120],
            ['Amazon Flowers Straightening', 3500, 'Hair & Scalp Treatments', '4-5 hrs', 270],
            ['Manicure / Pedicure', 49, 'Nails & Spa Services', '1 hr', 60],
            ['Eyelash Extensions', 599, 'Lash & Brows Specials', '1.5 hrs', 90],
            ['Basic Facial', 499, 'Facial Services', '1 hr', 60],
        ],
        'yashano' => [
            ['Basic Facial Care / Acne Clear', 499, 'Facial Services', '1 hr', 60],
            ['Radiant Gluta Push (3000mg + Antioxidants)', 999, 'Aesthetics Specials', '30 min', 30],
            ['Luminous Glow Drip (4500mg Skin Whitening)', 1499, 'Aesthetics Specials', '1 hr', 60],
            ['Vampire Facial (Platelet-Rich Plasma / PRP)', 2499, 'Aesthetics Specials', '1.5 hrs', 90],
            ['Haircut w/ Blowdry (Premium)', 49, 'Complete Salon Services', '45 min', 45],
            ['Lip Pigmentation Special', 999, 'Aesthetics Specials', '2 hrs', 120],
            ['Microblading Deluxe', 1999, 'Aesthetics Specials', '2.5 hrs', 150],
            ['Signature Facial Treatment', 499, 'Facial Services', '1.5 hrs', 90],
        ],
        'cabangan' => [
            ['Signature Haircut & Blow Dry', 30, 'Complete Salon Services', '45 min', 45],
            ['Korean Lash Lift w/ Tint', 599, 'Lash & Brows Specials', '1 hr', 60],
            ['Eyelash Extensions', 599, 'Lash & Brows Specials', '1.5 hrs', 90],
            ['Hair Color with Keratin', 499, 'Hair & Scalp Treatments', '2 hrs', 120],
            ['Brazilian Treatment / Botox', 899, 'Hair & Scalp Treatments', '2-3 hrs', 150],
            ['Basic Facial Care', 499, 'Facial Services', '1 hr', 60],
            ['Acne Clear Facial', 599, 'Facial Services', '1.5 hrs', 90],
            ['Organic Rebonding Spec', 999, 'Hair & Scalp Treatments', '3-4 hrs', 210],
        ],
    ];

    $serviceIds = [];
    foreach ($serviceCatalog as $branchKey => $services) {
        foreach ($services as [$name, $price, $category, $durationLabel, $durationMinutes]) {
            $stmt = $pdo->prepare('SELECT id FROM services WHERE branch_id = ? AND service_name = ?');
            $stmt->execute([$branchIds[$branchKey], $name]);
            $id = $stmt->fetchColumn();
            if (!$id) {
                $ins = $pdo->prepare('INSERT INTO services (branch_id, service_name, category, duration_minutes, duration_label, price) VALUES (?, ?, ?, ?, ?, ?)');
                $ins->execute([$branchIds[$branchKey], $name, $category, $durationMinutes, $durationLabel, $price]);
                $id = $pdo->lastInsertId();
            }
            $serviceIds[$branchKey][$name] = (int) $id;
        }
    }

    $staffCatalog = [
        ['Dave Alvarez', 'cabangan', 'Hair Color Specialist'],
    ];
    $staffRoleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'Staff'")->fetchColumn();

    $employeeIds = [];
    foreach ($staffCatalog as [$fullName, $branchKey, $position]) {
        [$firstName, $lastName] = array_pad(explode(' ', $fullName, 2), 2, '');
        $email = strtolower(str_replace(' ', '.', $fullName)) . '@leomejillanosalon.test';
        $phone = '09' . str_pad((string) (crc32($fullName) % 1000000000), 9, '0', STR_PAD_LEFT);

        $userStmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $userStmt->execute([$email]);
        $userId = $userStmt->fetchColumn();
        if (!$userId) {
            $insUser = $pdo->prepare('INSERT INTO users (role_id, email, password) VALUES (?, ?, ?)');
            $insUser->execute([$staffRoleId, $email, password_hash('Staff@12345', PASSWORD_DEFAULT)]);
            $userId = $pdo->lastInsertId();
        }

        $empStmt = $pdo->prepare('SELECT id FROM employees WHERE user_id = ?');
        $empStmt->execute([$userId]);
        $employeeId = $empStmt->fetchColumn();
        if (!$employeeId) {
            $insEmp = $pdo->prepare('INSERT INTO employees (user_id, branch_id, first_name, last_name, phone_number, position, hire_date) VALUES (?, ?, ?, ?, ?, ?, CURDATE())');
            $insEmp->execute([$userId, $branchIds[$branchKey], $firstName, $lastName, $phone, $position]);
            $employeeId = $pdo->lastInsertId();
        }
        $employeeIds[$fullName] = (int) $employeeId;
    }

    $promoCatalog = [
        'daraga' => ['code' => 'ORGREBOND2026', 'title' => 'ORGANIC REBONDING DEALS', 'description' => 'Available at any hair length. Complete styling blowout and treatment inclusion.', 'service' => 'Organic Rebonding (Any Length)', 'price' => 999, 'originalPrice' => 2500],
        'yashano' => ['code' => 'GLUTAPUSH2026', 'title' => 'RADIANT GLUTA PUSH PACKAGE', 'description' => 'Includes 3000mg Gluta Push + Antioxidants. Boost your glow!', 'service' => 'Radiant Gluta Push (3000mg + Antioxidants)', 'price' => 999, 'originalPrice' => 1500],
        'cabangan' => ['code' => 'LASHLIFT2026', 'title' => 'KOREAN LASH LIFT & TINT PROMO', 'description' => 'Achieve perfectly curled and tinted lashes for a stunning look.', 'service' => 'Korean Lash Lift w/ Tint', 'price' => 499, 'originalPrice' => 700],
    ];
    foreach ($promoCatalog as $branchKey => $promo) {
        $stmt = $pdo->prepare('SELECT id FROM promotions WHERE promo_code = ?');
        $stmt->execute([$promo['code']]);
        if (!$stmt->fetchColumn()) {
            $serviceId = $serviceIds[$branchKey][$promo['service']];
            $ins = $pdo->prepare("INSERT INTO promotions (branch_id, service_id, title, promo_code, description, discount_type, discount_value, price, original_price, start_date, end_date, is_active) VALUES (?, ?, ?, ?, ?, 'Fixed Amount', ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 1)");
            $ins->execute([
                $branchIds[$branchKey], $serviceId, $promo['title'], $promo['code'], $promo['description'],
                $promo['originalPrice'] - $promo['price'], $promo['price'], $promo['originalPrice'],
            ]);
        }
    }

    $customerRoleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'Customer'")->fetchColumn();
    $demoEmail = 'patricia.a@example.com';
    $demoPhone = '09165368016';
    $demoPicture = 'https://placehold.co/100x100/7e22ce/f3e8ff/png?text=PA';

    $userStmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $userStmt->execute([$demoEmail]);
    $demoUserId = $userStmt->fetchColumn();
    if (!$demoUserId) {
        $insUser = $pdo->prepare('INSERT INTO users (role_id, email, password) VALUES (?, ?, ?)');
        $insUser->execute([$customerRoleId, $demoEmail, password_hash('Password123!', PASSWORD_DEFAULT)]);
        $demoUserId = $pdo->lastInsertId();
    }

    $custStmt = $pdo->prepare('SELECT id FROM customers WHERE user_id = ?');
    $custStmt->execute([$demoUserId]);
    $demoCustomerId = $custStmt->fetchColumn();
    if (!$demoCustomerId) {
        $insCust = $pdo->prepare('INSERT INTO customers (user_id, first_name, last_name, phone_number, loyalty_points, notify_email, notify_sms, profile_picture) VALUES (?, ?, ?, ?, 1250, 1, 1, ?)');
        $insCust->execute([$demoUserId, 'Patricia', 'Almonicar', $demoPhone, $demoPicture]);
        $demoCustomerId = $pdo->lastInsertId();
    } else {
        $pdo->prepare('UPDATE customers SET loyalty_points = 1250, notify_email = 1, notify_sms = 1, profile_picture = ? WHERE id = ?')
            ->execute([$demoPicture, $demoCustomerId]);
    }

    $appointmentSeed = [
    ];

    $appointmentIds = [];
    foreach ($appointmentSeed as [$branchKey, $employeeName, $serviceName, $refCode, $datetime, $status, $totalPrice, $reminderSent]) {
        $stmt = $pdo->prepare('SELECT id FROM appointments WHERE reference_code = ?');
        $stmt->execute([$refCode]);
        $apptId = $stmt->fetchColumn();
        if (!$apptId) {
            $ins = $pdo->prepare('INSERT INTO appointments (reference_code, customer_id, employee_id, branch_id, appointment_datetime, status, total_price, reminder_sent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$refCode, $demoCustomerId, $employeeIds[$employeeName], $branchIds[$branchKey], $datetime, $status, $totalPrice, $reminderSent]);
            $apptId = $pdo->lastInsertId();

            $serviceId = $serviceIds[$branchKey][$serviceName];
            $pdo->prepare('INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)')->execute([$apptId, $serviceId]);
        }
        $appointmentIds[$refCode] = (int) $apptId;
    }

    $reviewSeed = [
        ['LMS-9021AE', 5, 'Maria was fantastic! My hair has never felt so smooth and healthy. The salon has a very relaxing atmosphere. Will definitely come back for this service.', '2026-07-06 10:00:00'],
        ['LMS-8054AF', 5, 'Anna is a true artist! My lashes look amazing, and the tint is perfect. The process was so comfortable I almost fell asleep. Highly recommend!', '2026-06-15 16:00:00'],
        ['LMS-7811AG', 4, 'Very relaxing facial. My skin feels refreshed. The ambiance at the Yashano branch is top-notch. One star off because the waiting time was a bit long, but the service itself was great.', '2026-05-20 12:00:00'],
    ];
    foreach ($reviewSeed as [$refCode, $rating, $comment, $createdAt]) {
        $apptId = $appointmentIds[$refCode];
        $stmt = $pdo->prepare('SELECT id FROM feedback WHERE appointment_id = ?');
        $stmt->execute([$apptId]);
        if (!$stmt->fetchColumn()) {
            $ins = $pdo->prepare('INSERT INTO feedback (appointment_id, customer_id, rating, comments, is_public, created_at) VALUES (?, ?, ?, ?, 1, ?)');
            $ins->execute([$apptId, $demoCustomerId, $rating, $comment, $createdAt]);
        }
    }

    $homeServiceSeed = [
        ['123 Main St, Legazpi City', 'Wedding', '2026-08-15', 'Need hair and makeup for bride and 3 bridesmaids.', 'Pending Review', '2026-07-05 09:00:00'],
        ['456 Rizal St, Daraga, Albay', 'Photoshoot', '2026-07-20', 'Glam makeup for a fashion shoot.', 'Confirmed', '2026-07-01 09:00:00'],
    ];
    foreach ($homeServiceSeed as [$address, $eventType, $preferredDate, $requests, $status, $submittedAt]) {
        $stmt = $pdo->prepare('SELECT id FROM home_service_requests WHERE customer_id = ? AND address = ? AND event_type = ?');
        $stmt->execute([$demoCustomerId, $address, $eventType]);
        if (!$stmt->fetchColumn()) {
            $ins = $pdo->prepare('INSERT INTO home_service_requests (customer_id, address, event_type, preferred_date, requests, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$demoCustomerId, $address, $eventType, $preferredDate, $requests, $status, $submittedAt]);
        }
    }

    $pdo->commit();

    echo "Seed complete.\n";
    echo "Demo customer login -> email: {$demoEmail} / password: Password123!\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
