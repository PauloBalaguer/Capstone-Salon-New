<?php
/** Actual collections, independent of appointment/service dates and completion status. */
final class RevenueReport
{
    public static function source(): string
    {
        return "
        SELECT CONCAT('deposit-',a.id) AS id, a.reference_code AS reference, br.branch_key AS branchId,
            br.branch_name AS branchName, COALESCE((SELECT MIN(pt.created_at) FROM payment_transactions pt WHERE pt.appointment_id=a.id AND pt.event='payment_paid'),a.deposit_recorded_at) AS collectedAt,
            'Reservation payment' AS kind, a.deposit_amount AS amount, a.deposit_method AS method
        FROM appointments a JOIN branches br ON br.id=a.branch_id
        WHERE a.deposit_paid=1 AND a.deposit_amount>0 AND (a.deposit_recorded_by IS NOT NULL OR EXISTS
            (SELECT 1 FROM payment_transactions pt WHERE pt.appointment_id=a.id AND pt.event='payment_paid'))
        UNION ALL
        SELECT CONCAT('checkout-',p.id), a.reference_code, br.branch_key, br.branch_name, p.payment_date,
            'Cashier checkout', p.amount, p.payment_method
        FROM payments p JOIN appointments a ON a.id=p.appointment_id JOIN branches br ON br.id=a.branch_id
        WHERE p.status='Paid'
        UNION ALL
        SELECT CONCAT('home-',hp.id), h.reference_code, 'home-service',
            'Home Service (off-site)', hp.created_at,
            IF(hp.kind='deposit','Event reservation payment','Event balance payment'), hp.amount, hp.method
        FROM home_service_payments hp JOIN home_service_requests h ON h.id=hp.home_service_request_id
        WHERE (hp.recorded_by IS NOT NULL OR hp.method IN ('PayMongo','PayMongo-GCash','PayMongo-Maya','PayMongo-Card')) AND NOT EXISTS
            (SELECT 1 FROM home_service_payments earlier WHERE earlier.home_service_request_id=hp.home_service_request_id
             AND earlier.kind=hp.kind AND earlier.method=hp.method AND earlier.reference=hp.reference
             AND hp.reference IS NOT NULL AND hp.reference<>'' AND earlier.id<hp.id)
        UNION ALL
        SELECT CONCAT('refund-',r.id), r.reference_code COLLATE utf8mb4_general_ci, COALESCE(ab.branch_key,'home-service'),
            COALESCE(ab.branch_name,'Home Service (off-site)'), r.created_at,
            'Refund completed', -r.amount, NULL
        FROM payment_transactions r LEFT JOIN appointments a ON a.reference_code=r.reference_code COLLATE utf8mb4_general_ci
        LEFT JOIN branches ab ON ab.id=a.branch_id LEFT JOIN home_service_requests h ON h.reference_code=r.reference_code COLLATE utf8mb4_general_ci
        WHERE r.event='refunded' AND r.amount>0 AND (a.id IS NOT NULL OR h.id IS NOT NULL) AND NOT EXISTS
            (SELECT 1 FROM payment_transactions earlier WHERE earlier.event='refunded' AND earlier.reference_code=r.reference_code
             AND earlier.external_id <=> r.external_id AND earlier.id<r.id)
        ";
    }

    public static function filterParts(array $filters, string $dateColumn = 'collectedAt', string $branchColumn = 'branchId'): array
    {
        $where=[];$params=[];
        foreach(['from','to'] as $key) {
            $value=$filters[$key]??'';
            if (!is_string($value)) throw new InvalidArgumentException('Please choose a valid report date.');
            if ($value==='') continue;
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if (!$date || $date->format('Y-m-d')!==$value) throw new InvalidArgumentException('Please choose a valid report date.');
            $where[]=$dateColumn.($key==='from'?' >= ?':' < ?');
            $params[]=$key==='from'?$value:$date->modify('+1 day')->format('Y-m-d');
        }
        if (!empty($filters['from']) && !empty($filters['to']) && $filters['from']>$filters['to']) throw new InvalidArgumentException('The start date must be on or before the end date.');
        $branch=$filters['branch']??'all';
        if (!is_string($branch)) throw new InvalidArgumentException('Please select a valid branch.');
        if ($branch!=='all') {$where[]=$branchColumn.' = ?';$params[]=$branch;}
        return [$where,$params];
    }

    public static function rows(PDO $pdo, array $filters): array
    {
        [$where,$params]=self::filterParts($filters);
        $stmt=$pdo->prepare('SELECT * FROM ('.self::source().') collections'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY collectedAt DESC,id DESC');
        $stmt->execute($params);$rows=$stmt->fetchAll();
        foreach($rows as &$row){$row['amount']=(float)$row['amount'];$row['date']=substr((string)$row['collectedAt'],0,10);}unset($row);
        return $rows;
    }

    public static function totals(PDO $pdo): array
    {
        $rows=$pdo->query('SELECT branchId,SUM(amount) AS amount FROM ('.self::source().') collections GROUP BY branchId')->fetchAll();
        return array_column($rows,'amount','branchId');
    }
}
