<?php
/** Read-only selection of mixed salon/home-service booking keys before detail aggregation. */
final class AdminBookingList
{
    public static function select(PDO $pdo, array $input): array
    {
        $page = filter_var($input['bookingPage'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $size = filter_var($input['pageSize'] ?? 10, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>100]]);
        if ($page === false || $size === false) throw new InvalidArgumentException('Invalid booking page.');
        foreach (['search','branch','status','paymentStatus','dateFrom','dateTo'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) throw new InvalidArgumentException('Invalid booking filter.');
        }
        $from = trim($input['dateFrom'] ?? ''); $to = trim($input['dateTo'] ?? '');
        foreach ([$from,$to] as $date) {
            if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || date('Y-m-d',strtotime($date)) !== $date)) throw new InvalidArgumentException('Choose a valid appointment date.');
        }
        if ($from !== '' && $to !== '' && $from > $to) throw new InvalidArgumentException('The end date must be on or after the start date.');
        $conditions=[]; $params=[];
        $search=mb_substr(trim($input['search'] ?? ''),0,150);
        if ($search !== '') {
            // Literal substring search, including names/references containing % or _.
            $conditions[]='(LOCATE(?, clientName) > 0 OR LOCATE(?, reference) > 0)';
            array_push($params,$search,$search);
        }
        foreach (['branch'=>'branchKey','paymentStatus'=>'paymentStatus'] as $field=>$column) {
            $value=trim($input[$field] ?? 'all');
            if ($value !== '' && $value !== 'all') { $conditions[]="$column = ?"; $params[]=$value; }
        }
        $status=trim($input['status'] ?? 'all');
        if ($status==='refunds') $conditions[]="paymentStatus IN ('Refund Due','Refund Processing')";
        elseif ($status!=='' && $status!=='all') { $conditions[]='bookingStatus = ?'; $params[]=$status; }
        if ($from!=='') { $conditions[]='appointmentDate >= ?'; $params[]=$from; }
        if ($to!=='') { $conditions[]='appointmentDate <= ?'; $params[]=$to; }
        $source="SELECT a.id AS internalId, 'salon' AS kind, a.reference_code AS reference,
            CONCAT(c.first_name, ' ', c.last_name) AS clientName, br.branch_key AS branchKey,
            a.status AS bookingStatus, a.payment_status AS paymentStatus, DATE(a.appointment_datetime) AS appointmentDate, a.created_at AS createdAt
            FROM appointments a JOIN customers c ON c.id=a.customer_id LEFT JOIN branches br ON br.id=a.branch_id
            UNION ALL SELECT h.id, 'home', h.reference_code, CONCAT(c.first_name, ' ', c.last_name), 'off-site',
            h.status, h.payment_status, h.preferred_date, h.submitted_at
            FROM home_service_requests h JOIN customers c ON c.id=h.customer_id";
        $where=$conditions ? ' WHERE '.implode(' AND ',$conditions) : '';
        // Keep count and page keys consistent while new bookings are submitted.
        $ownsTransaction=!$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM ($source) bookings$where"); $stmt->execute($params); $total=(int)$stmt->fetchColumn();
            $pages=max(1,(int)ceil($total/$size)); $page=min($page,$pages); $offset=($page-1)*$size;
            $stmt=$pdo->prepare("SELECT internalId, kind FROM ($source) bookings$where ORDER BY createdAt DESC, reference DESC, kind DESC, internalId DESC LIMIT $size OFFSET $offset");
            $stmt->execute($params); $keys=$stmt->fetchAll();
            if ($ownsTransaction) $pdo->commit();
        } catch(Throwable $e) { if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return ['salonIds'=>array_column(array_filter($keys,fn($row)=>$row['kind']==='salon'),'internalId'),
            'homeIds'=>array_column(array_filter($keys,fn($row)=>$row['kind']==='home'),'internalId'),
            'pagination'=>['page'=>$page,'pageSize'=>$size,'total'=>$total,'totalPages'=>$pages]];
    }
}
